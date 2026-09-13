<?php

namespace Tests\Unit\Integrations\PlatformAccess;

use App\Integrations\PlatformAccess\PlatformAccessProtocol;
use App\Integrations\PlatformAccess\RefreshTokenRotationSink;
use PHPUnit\Framework\TestCase;

class RefreshTokenRotationSinkTest extends TestCase
{
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_replacement_file_is_created_with_only_the_closed_sink_document(): void
    {
        $path = sys_get_temp_dir().'/music-map-rotation-'.bin2hex(random_bytes(8));
        $this->temporaryPaths[] = $path;
        $token = 'replacement-token-canary';
        $failure = (new RefreshTokenRotationSink($path))->write('spotify', 'technical', $token);

        $this->assertNull($failure);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringEndsWith("\n", $contents);
        $this->assertSame([
            'protocol' => PlatformAccessProtocol::VERSION,
            'provider' => 'spotify',
            'principal' => 'technical',
            'refresh_token' => $token,
        ], json_decode($contents, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_absent_replacement_token_does_not_create_or_change_a_sink(): void
    {
        $path = sys_get_temp_dir().'/music-map-rotation-'.bin2hex(random_bytes(8));
        $this->temporaryPaths[] = $path;

        $failure = (new RefreshTokenRotationSink($path))->write('youtube', 'tester', null);

        $this->assertNull($failure);
        $this->assertFileDoesNotExist($path);
    }

    public function test_missing_operation_directory_fails_without_leaking_the_replacement_token(): void
    {
        $path = sys_get_temp_dir().'/music-map-missing-'.bin2hex(random_bytes(8)).'/replacement.json';
        $token = 'replacement-token-canary';
        $failure = (new RefreshTokenRotationSink($path))->write('youtube', 'tester', $token);

        $this->assertNotNull($failure);
        $this->assertSame('refresh-token-rotation-required', $failure->category);
        $this->assertSame(1, $failure->exitCode());
        $this->assertStringNotContainsString($token, $failure->stdout());
        $this->assertStringNotContainsString($token, $failure->stderr());
        $this->assertFileDoesNotExist($path);
    }

    public function test_invalid_replacement_token_is_not_written_or_echoed(): void
    {
        $path = $this->temporaryFile();
        $token = "replacement\ntoken";
        $failure = (new RefreshTokenRotationSink($path))->write('spotify', 'tester', $token);

        $this->assertNotNull($failure);
        $this->assertSame('', file_get_contents($path));
        $this->assertStringNotContainsString($token, $failure->stderr());
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'music-map-rotation-');
        $this->assertIsString($path);
        $this->temporaryPaths[] = $path;

        return $path;
    }
}
