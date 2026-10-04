<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $config = $app->make('config');

        /** Stop before RefreshDatabase can migrate or erase a persistent database. */
        if ($config->get('app.env') !== 'testing'
            || $config->get('database.default') !== 'sqlite'
            || $config->get('database.connections.sqlite.database') !== ':memory:'
            || $config->get('database.connections.sqlite.url')) {
            throw new RuntimeException('Tests require in-memory SQLite. Clear cached configuration and check phpunit.xml before continuing.');
        }

        return $app;
    }
}
