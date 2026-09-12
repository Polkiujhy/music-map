<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PlatformAccessConfigurationTest extends TestCase
{
    public function test_streaming_services_map_the_published_symbolic_environment_inputs(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 2).'/config/services.php');

        $this->assertIsString($configuration);

        foreach ([
            "'spotify' => [",
            "'client_id' => env('SPOTIFY_CLIENT_ID')",
            "'client_secret' => env('SPOTIFY_CLIENT_SECRET')",
            "'redirect' => env('SPOTIFY_REDIRECT_URI')",
            "'technical_refresh_token' => env('SPOTIFY_TECHNICAL_REFRESH_TOKEN')",
            "'youtube' => [",
            "'api_key' => env('YOUTUBE_API_KEY')",
            "'redirect' => env('YOUTUBE_REDIRECT_URI')",
            "'technical_refresh_token' => env('YOUTUBE_TECHNICAL_REFRESH_TOKEN')",
        ] as $mapping) {
            $this->assertSame(1, substr_count($configuration, $mapping), "Missing or duplicate mapping: {$mapping}");
        }

        $this->assertSame(2, substr_count($configuration, "'client_id' => env('GOOGLE_CLIENT_ID')"));
        $this->assertSame(2, substr_count($configuration, "'client_secret' => env('GOOGLE_CLIENT_SECRET')"));
        $this->assertStringNotContainsString("'technical_refresh_token' => env('GOOGLE_", $configuration);
    }

    public function test_example_uses_fail_closed_secrets_and_invalid_callback_hosts(): void
    {
        $environment = file_get_contents(dirname(__DIR__, 2).'/.env.example');

        $this->assertIsString($environment);

        foreach ([
            'SPOTIFY_CLIENT_ID',
            'SPOTIFY_CLIENT_SECRET',
            'SPOTIFY_TECHNICAL_REFRESH_TOKEN',
            'YOUTUBE_API_KEY',
            'YOUTUBE_TECHNICAL_REFRESH_TOKEN',
        ] as $secretName) {
            $this->assertStringContainsString("{$secretName}=__REQUIRED_RUNTIME_SECRET__", $environment);
        }

        $this->assertStringContainsString(
            'SPOTIFY_REDIRECT_URI=https://music-map.example.invalid/integrations/spotify/callback',
            $environment,
        );
        $this->assertStringContainsString(
            'YOUTUBE_REDIRECT_URI=https://music-map.example.invalid/integrations/youtube/callback',
            $environment,
        );
    }
}
