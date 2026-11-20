<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once dirname(__DIR__).'/modules/theme-support/tests/Fixtures/ComposerAvailability.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// The runtime-selection rule reads config('modules.*'), which needs a booted container.
pest()->extend(TestCase::class)->in('Architecture');

pest()->extend(TestCase::class)->in('../modules');

pest()->use(RefreshDatabase::class)->in('../modules/*/tests/Feature');
