<?php

namespace GearDev\HttpSwowServer\Processes;

use GearDev\Core\ContextStorage\ContextStorage;
use GearDev\Coroutines\Co\CoFactory;
use GearDev\HttpServer\ServerProcess\AbstractHttpProcess;
use GearDev\Processes\Attributes\Process;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestTerminated;
use Psr\Http\Message\ServerRequestInterface;
use Swow\CoroutineException;
use Swow\Errno;
use Swow\Http\Protocol\ProtocolException;
use Swow\Psr7\Server\Server;
use Swow\Psr7\Server\ServerConnection;
use Swow\Socket;
use Swow\SocketException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;

#[Process(processName: 'http-server', serverOnly: true)]
class HttpProcess extends AbstractHttpProcess
{
    protected function createServer(): Server {
        $host = '0.0.0.0';
        $port = config('gear.http-swow-server.port', 8080);
        $bindFlag = Socket::BIND_FLAG_NONE;

        $server = new Server(Socket::TYPE_TCP);
        $server->bind($host, $port, $bindFlag)->listen();
        Log::info("Http server starting at $host:$port");
        return $server;
    }

    protected function run(): bool
    {
        $server = $this->createServer();
        CoFactory::createCo($this->name . '_server')->charge(function: function (Server $server) {
            while (true) {
                try {
                    $connection = null;
                    $connection = $server->acceptConnection();
                    CoFactory::createCo('http_consumer')
                        ->charge(function: function (ServerConnection $connection): void {
                            try {
                                $request = $connection->recvHttpRequest();
                                if ($this->isQueryAccessingFile($request)) {
                                    $swowResponse = $this->tryToServeFile($request);
                                    $connection->sendHttpResponse($swowResponse);
                                    $connection->close();
                                    return;
                                }
                                $convertedHeaders = [];
                                foreach ($request->getHeaders() as $key => $header) {
                                    $convertedHeaders['HTTP_' . $key] = $header[0];
                                }
                                $serverParams = array_merge([
                                    'REQUEST_URI' => $request->getUri()->getPath(),
                                    'REQUEST_METHOD' => $request->getMethod(),
                                    'QUERY_STRING' => $request->getUri()->getQuery(),
                                ], $request->getServerParams(), $convertedHeaders);
                                $parsedBody = $this->buildNestedArrayFromParsedBody($request->getParsedBody());
                                $symfonyRequest = new \Symfony\Component\HttpFoundation\Request(
                                    query: $request->getQueryParams(),
                                    request: $parsedBody,
                                    attributes: [...$request->getAttributes(), 'transport'=>'http'],
                                    cookies: $request->getCookieParams(),
                                    files: $request->getUploadedFiles(),
                                    server: $serverParams,
                                    content: $request->getBody()->getContents()
                                );

                                $laravelRequest = Request::createFromBase($symfonyRequest);


                                $this->dispatchRequestReceivedEvent($laravelRequest);

                                $kernel = app()->make(HttpKernelContract::class);
                                $response = $kernel->handle($laravelRequest);
                                $this->dispatchRequestHandledEvent($laravelRequest, $response);

                                if ($response instanceof BinaryFileResponse) {
                                    $swowResponse = $this->createBinaryFileResponse($response);
                                } elseif ($response instanceof \Illuminate\Http\Response) {
                                    $swowResponse = $this->createDefaultResponse($response);
                                } elseif ($response instanceof \Illuminate\Http\RedirectResponse) {
                                    $swowResponse = $this->createRedirectResponse($response);
                                } elseif ($response instanceof \Illuminate\Http\JsonResponse) {
                                    $swowResponse = $this->createJsonResponse($response);
                                } else {
                                    $connection->error(510, 'Response Type is not supported: '.get_class($response), close: true);
                                }
                                $connection->sendHttpResponse($swowResponse);

                            } catch (ProtocolException $exception) {
                                $connection->error($exception->getCode(), $exception->getMessage(), close: true);
                            }

                            $connection->close();
                            $this->dispatchRequestTerminatedEvent($laravelRequest, $response);

                            $route = $laravelRequest->route();

                            if ($route instanceof Route && method_exists($route, 'flushController')) {
                                $route->flushController();
                            }
                        })->args($connection)->runWithClonedDiContainer();
                } catch (SocketException|CoroutineException $exception) {
                    if (in_array($exception->getCode(), [Errno::EMFILE, Errno::ENFILE, Errno::ENOMEM], true)) {
                        sleep(1);
                    } else {
                        throw $exception;
                    }
                }
            }
        })->args($server)->runWithClonedDiContainer();
        return true;
    }

    private function createBinaryFileResponse(BinaryFileResponse $response)
    {
        $file = $response->getFile();
        $swowResponse = new \Swow\Psr7\Message\Response();
        $swowResponse->setBody($file->getContent());
        $swowResponse->setStatus($response->getStatusCode());
        $swowResponse->setHeaders($response->headers->all());
        $swowResponse->setProtocolVersion($response->getProtocolVersion());
        return $swowResponse;
    }

    protected function dispatchRequestHandledEvent(Request $laravelRequest, $response)
    {
        $dispatcher = app()->make(\Illuminate\Contracts\Events\Dispatcher::class);
        $dispatcher->dispatch(new RequestHandled(app(), $laravelRequest, $response));
    }

    protected function dispatchRequestReceivedEvent(Request $laravelRequest)
    {
        $dispatcher = app()->make(\Illuminate\Contracts\Events\Dispatcher::class);
        $dispatcher->dispatch(new \Laravel\Octane\Events\RequestReceived(ContextStorage::getMainApplication(), app(), $laravelRequest));
    }

    protected function dispatchRequestTerminatedEvent(Request $laravelRequest, $response)
    {
        $dispatcher = app()->make(\Illuminate\Contracts\Events\Dispatcher::class);
        $dispatcher->dispatch(new RequestTerminated(ContextStorage::getMainApplication(), app(), $laravelRequest, $response));
    }

    public static function buildNestedArrayFromParsedBody(array $parsedBody) {
        //the parsed body is like provider[title] => 'some title', provider[slug] => 'some slug'
        //we need to convert it to ['provider' => ['title' => 'some title', 'slug' => 'some slug']]
        //also we now, that levels can be more than 2, so we need to build nested arrays

        $result = [];
        foreach ($parsedBody as $key => $value) {
            $keys = explode('[', $key);
            $keys = array_map(fn($key) => str_replace(']', '', $key), $keys);
            $nestedArray = [];
            $nestedArray[$keys[count($keys)-1]] = $value;
            for ($i = count($keys)-2; $i >= 0; $i--) {
                $nestedArray = [$keys[$i] => $nestedArray];
            }
            $result = array_merge_recursive($result, $nestedArray);
        }
        return $result;
    }

    private function tryToServeFile(ServerRequestInterface $request)
    {
        $swowResponse = new \Swow\Psr7\Message\Response();
        if (file_exists(public_path($request->getUri()->getPath()))) {
            $file = new File(public_path($request->getUri()->getPath()));
            $contentOfFIle = $file->getContent();
            $swowResponse->setBody($contentOfFIle);
            $swowResponse->setStatus(200);
            if ($file->getExtension()=='css') {
                $swowResponse->setHeaders([
                    'Content-Type' => 'text/css',
                ]);
            } elseif ($file->getExtension()=='js') {
                $swowResponse->setHeaders([
                    'Content-Type' => 'application/javascript',
                ]);
            }

            $swowResponse->setProtocolVersion('1.1');
            return $swowResponse;
        } else {
            $swowResponse->setStatus(404);
            $swowResponse->setBody('File not found');
            $swowResponse->setHeaders([]);
            $swowResponse->setProtocolVersion('1.1');
            return $swowResponse;
        }
    }

    /**
     * @param \Illuminate\Http\JsonResponse $response
     * @return \Swow\Psr7\Message\Response
     */
    public function createJsonResponse(\Illuminate\Http\JsonResponse $response): \Swow\Psr7\Message\Response
    {
        $swowResponse = new \Swow\Psr7\Message\Response();
        $swowResponse->setBody($response->getContent());
        $swowResponse->setStatus($response->getStatusCode());
        $swowResponse->setHeaders($response->headers->all());
        $swowResponse->setProtocolVersion($response->getProtocolVersion());
        return $swowResponse;
    }

    /**
     * @param \Illuminate\Http\RedirectResponse $response
     * @return \Swow\Psr7\Message\Response
     */
    public function createRedirectResponse(\Illuminate\Http\RedirectResponse $response): \Swow\Psr7\Message\Response
    {
        $swowResponse = new \Swow\Psr7\Message\Response();
        $swowResponse->setBody($response->getContent());
        $swowResponse->setStatus($response->getStatusCode());
        $swowResponse->setHeaders($response->headers->all());
        $swowResponse->setProtocolVersion($response->getProtocolVersion());
        return $swowResponse;
    }

    /**
     * @param \Illuminate\Http\Response $response
     * @return \Swow\Psr7\Message\Response
     */
    public function createDefaultResponse(\Illuminate\Http\Response $response): \Swow\Psr7\Message\Response
    {
        $swowResponse = new \Swow\Psr7\Message\Response();
        $swowResponse->setBody($response->getContent());
        $swowResponse->setStatus($response->getStatusCode());
        $swowResponse->setHeaders($response->headers->all());
        $swowResponse->setProtocolVersion($response->getProtocolVersion());
        return $swowResponse;
    }

    private function isQueryAccessingFile($request): bool
    {
        if (str_starts_with($request->getUri()->getPath(), '/build')) {
            return true;
        } elseif (str_starts_with($request->getUri()->getPath(), '/vendor')) {
            return true;
        }
        return false;
    }

}
