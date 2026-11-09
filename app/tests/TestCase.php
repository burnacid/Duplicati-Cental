<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests empty the database (RefreshDatabase). Refuse to run unless it is the
     * in-memory SQLite test database, so a cached config (php artisan optimize /
     * config:cache) or stray environment variables can never point the test suite
     * at a real database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $default = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$default}.database");

        if ($app->configurationIsCached() || $app->environment() !== 'testing' || $default !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against database "%s" (%s, environment "%s"%s). '
                . 'Tests only run on the in-memory SQLite database. Run "php artisan optimize:clear" and try again.',
                $database,
                $default,
                $app->environment(),
                $app->configurationIsCached() ? ', configuration is cached' : '',
            ));
        }

        return $app;
    }
}
