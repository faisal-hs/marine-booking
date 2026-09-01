<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NoStrayHttpRequestsTest extends TestCase
{
    public function test_unmocked_outbound_http_call_throws_instead_of_hitting_the_network(): void
    {
        $this->expectException(\RuntimeException::class);

        Http::get('https://example.com/this-should-never-actually-be-requested');
    }

    public function test_mocked_outbound_http_call_still_works_normally(): void
    {
        Http::fake();

        $response = Http::get('https://example.com/anything');

        $this->assertTrue($response->successful());
    }
}
