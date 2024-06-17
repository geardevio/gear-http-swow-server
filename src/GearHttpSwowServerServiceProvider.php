<?php

namespace GearDev\HttpSwowServer;

use GearDev\Collector\Collector\Collector;
use Illuminate\Support\ServiceProvider;

class GearHttpSwowServerServiceProvider extends ServiceProvider
{
    public function boot() {

    }


    public function register() {
        Collector::addPackageToCollector(__DIR__);
    }
}