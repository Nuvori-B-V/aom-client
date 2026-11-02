<?php

namespace Nuvori\AomClient\Tests;

use Nuvori\AomClient\AomClientServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AomClientServiceProvider::class];
    }
}
