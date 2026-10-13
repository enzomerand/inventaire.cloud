<?php

declare(strict_types=1);

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the whole Laravel application through the base TestCase.
| Unit tests stay framework-free on purpose: they must run without a container.
|
*/

pest()->extend(TestCase::class)->in('Feature');
