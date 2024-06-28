<?php

namespace GearDev\HttpSwowServer\Bridging;

use Swow\Psr7\Server\ServerConnection;

interface HttpCycleInterface
{
    public function onServerStart();

    public function onRequest(ServerConnection $connection);
}