<?php

namespace MadeByClowd\Sequenceable\Tests;

use Illuminate\Foundation\Application;
use MadeByClowd\Sequenceable\SequenceableServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Get package providers.
     *
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            SequenceableServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite in-memory
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Enable default sequenceable settings
        $app['config']->set('sequenceable.load_migrations', true);
    }
}
