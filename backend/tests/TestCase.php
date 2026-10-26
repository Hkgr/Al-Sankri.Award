<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase drops every table of the default connection. It may run
     * on SQLite (phpunit.xml) or on a MySQL/MariaDB database explicitly named
     * "*_schema_test", never on any other database.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        if (isset(class_uses_recursive(static::class)[RefreshDatabase::class])) {
            $connection = (string) config('database.default');
            $driver = (string) config("database.connections.{$connection}.driver");
            $database = (string) config("database.connections.{$connection}.database");

            if ($driver !== 'sqlite' && preg_match('/^[A-Za-z0-9_]+_schema_test$/', $database) !== 1) {
                throw new RuntimeException("Refusing to refresh [{$database}] on [{$driver}]: only SQLite or a disposable \"*_schema_test\" database may be used by tests.");
            }
        }

        return parent::setUpTraits();
    }
}
