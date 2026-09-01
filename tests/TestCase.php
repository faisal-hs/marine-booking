<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Step 26 hardening: the test environment already sets SMS_DRIVER=log
     * (phpunit.xml) so OTP sends never construct a real TaqnyatSmsGateway.
     * This is layer two of defense-in-depth — even if a future change
     * accidentally re-points SMS_DRIVER at 'taqnyat' during tests, or any
     * other code path attempts an un-mocked outbound HTTP call, this makes
     * the test fail loudly with a clear exception instead of silently
     * succeeding or hanging on a real network call.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
