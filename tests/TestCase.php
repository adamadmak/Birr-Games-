<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The money services memoise their table checks and the settings model
        // caches every row; a fresh in-memory database per test needs both forgotten.
        \App\Services\BonusPlay::reset();
        \App\Services\DepositBonusService::resetTableCheck();
        \App\Models\PlatformSetting::flushValueCache();
    }

    /** Save a platform setting the way the admin would, and make it visible at once. */
    protected function setting(string $key, mixed $value): void
    {
        \App\Models\PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        \App\Models\PlatformSetting::flushValueCache();
    }
}
