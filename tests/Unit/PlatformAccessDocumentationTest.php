<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PlatformAccessDocumentationTest extends TestCase
{
    public function test_product_contract_records_provider_specific_import_and_visibility_limits(): void
    {
        $product = $this->projectFile('context/foundation/prd.md');
        $roadmap = $this->projectFile('context/foundation/roadmap.md');

        $this->assertStringContainsString('Spotify nie obiecuje importu arbitralnej cudzej playlisty', $product);
        $this->assertStringContainsString('YouTube pozwala odczytać playlistę publiczną albo `unlisted` przez API key', $product);
        $this->assertStringContainsString('Spotify nie przedstawia `public=false` jako ścisłej prywatności', $product);
        $this->assertStringContainsString('własną lub współdzieloną playlistę Spotify', $roadmap);
        $this->assertStringContainsString('publiczną/`unlisted` playlistę YouTube', $roadmap);
    }

    public function test_runbook_preserves_identity_separation_and_sanitized_evidence(): void
    {
        $runbook = $this->projectFile('docs/platform-access-readiness.md');

        foreach ([
            'Nie wolno użyć konta technicznego jako fallbacku',
            'Spotify Development Mode',
            'Google External/Testing',
            'PENDING',
            'BLOCKED',
            '50 utworów',
            'Nie zapisuj',
        ] as $contractText) {
            $this->assertStringContainsString($contractText, $runbook);
        }
    }

    private function projectFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);

        $this->assertIsString($contents);

        return $contents;
    }
}
