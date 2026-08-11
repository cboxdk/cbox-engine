<?php

declare(strict_types=1);

namespace Cbox\Engine\Tests;

use Cbox\Engine\EngineServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * A booted application with this package's provider in it.
 *
 * Testbench rather than a hand-rolled container, because several of these tests
 * are ABOUT the registry and the bindings — what the provider gives a consumer.
 * A test that asserted on those without booting would be asserting on nothing.
 */
abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [EngineServiceProvider::class];
    }
}
