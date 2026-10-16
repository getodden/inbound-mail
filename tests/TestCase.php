<?php

namespace Odden\InboundMail\Tests;

use Odden\InboundMail\InboundMailServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [InboundMailServiceProvider::class];
    }
}
