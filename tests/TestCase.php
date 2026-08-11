<?php

declare(strict_types=1);

namespace Cbox\Engine\Tests;

use Cbox\Engine\EngineServiceProvider;
use Cbox\Engine\Kind\HostPorts;
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

    /**
     * Pin the ports, so no test's outcome depends on the machine running it.
     *
     * `HostPorts` is normally read off the running cluster, and with no cluster
     * it falls back to probing 80/443/53 — so a deploy-path test asserting on a
     * URL passed on a laptop where Herd holds 443 and failed on a runner where
     * it does not. The behaviour under test is what the code does GIVEN ports;
     * which ports a machine happens to have free is not.
     *
     * The high pair, because that is the interesting case: it is the one that
     * puts a port in an address. A test that wants the privileged case says so
     * itself — see LocalTargetTest, which asserts both.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(HostPorts::class, HostPorts::high());
    }
}
