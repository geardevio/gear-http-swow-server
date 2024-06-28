<?php

namespace GearDev\HttpSwowServer\Container;

use GearDev\HttpSwowServer\Bridging\HttpCycleInterface;

class HttpSwowContainer
{
    private static HttpCycleInterface $httpCycleRealization;

    public static function setHttpCycleRealization(HttpCycleInterface $httpCycleRealization): void
    {
        self::$httpCycleRealization = $httpCycleRealization;
    }

    public static function getHttpCycleRealization(): HttpCycleInterface
    {
        return self::$httpCycleRealization;
    }
}