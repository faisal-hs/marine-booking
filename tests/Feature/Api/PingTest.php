<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class PingTest extends TestCase
{
    public function test_ping_endpoint_returns_standard_envelope(): void
    {
        $response = $this->getJson('/api/v1/ping');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'pong',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['timestamp'],
            ]);
    }

    public function test_unknown_api_route_returns_standard_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/this-route-does-not-exist');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Endpoint not found.',
            ]);
    }
}
