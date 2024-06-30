<?php

namespace GearDev\HttpSwowServer\Processes;

use GearDev\Coroutines\Co\CoFactory;
use GearDev\HttpSwowServer\Bridging\HttpCycleInterface;
use GearDev\HttpSwowServer\Container\HttpSwowContainer;
use GearDev\Processes\Attributes\Process;
use GearDev\Processes\ProcessesManagement\AbstractProcess;
use Swow\Psr7\Server\Server;
use Swow\Psr7\Server\ServerConnection;
use Swow\Socket;

#[Process(processName: 'http-server', serverOnly: true)]
class HttpProcess extends AbstractProcess
{
    protected function createServer(): Server {
        $host = '0.0.0.0';
        $port = 8080;
        $bindFlag = Socket::BIND_FLAG_NONE;

        $server = new Server(Socket::TYPE_TCP);
        $server->bind($host, $port, $bindFlag)->listen();
        echo json_encode(['msg'=>'Http server starting at '.$host.':'.$port]).PHP_EOL;
        return $server;
    }

    protected function resolveHttpServerCycle(): HttpCycleInterface {
        return HttpSwowContainer::getHttpCycleRealization();
    }

    protected function run(): bool
    {
        /** @var HttpCycleInterface $innerHttpServer */
        $innerHttpServer = $this->resolveHttpServerCycle();
        $server = $this->createServer();
        $innerHttpServer->onServerStart();
        CoFactory::createCo($this->name . '_server')->charge(function: function (Server $server, HttpCycleInterface $innerHttpServer): void {
            while (true) {
                try {
                    $connection = null;
                    $connection = $server->acceptConnection();
                    CoFactory::createCo('http_consumer')
                        ->charge(function: function (ServerConnection $connection, HttpCycleInterface $innerHttpServer): void {
                            $innerHttpServer->onRequest($connection);
                        })->args($connection, $innerHttpServer)->run();
                } catch (\Exception $exception) {
                    echo json_encode(['msg'=>'Http server error: '.$exception->getMessage()]).PHP_EOL;
                }
            }
        })->args($server, $innerHttpServer)->run();
        return true;
    }



}
