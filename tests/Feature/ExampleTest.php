<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_asset_urls_use_forwarded_https_scheme(): void
    {
        $response = $this
            ->withServerVariables([
                'HTTP_HOST' => 'music.adamis.me',
                'REMOTE_ADDR' => '172.23.0.8',
            ])
            ->withHeaders([
                'X-Forwarded-Host' => 'attacker.invalid',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/');

        $response->assertOk();
        $response->assertSee('https://localhost:8000/build/', false);
        $response->assertDontSee('http://localhost:8000/build/', false);
        $response->assertDontSee('attacker.invalid', false);
    }
}
