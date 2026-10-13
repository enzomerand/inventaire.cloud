<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boots the application, then checks the database before any trait (such as
     * RefreshDatabase) gets the chance to migrate it.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        TestDatabaseGuard::assertSafe($app->make('config'));

        return $app;
    }
}
