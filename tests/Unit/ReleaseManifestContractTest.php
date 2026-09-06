<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReleaseManifestContractTest extends TestCase
{
    private const SOURCE_SHA = '0123456789abcdef0123456789abcdef01234567';

    public function test_canonical_release_manifest_is_accepted(): void
    {
        [$status, $output] = $this->validate($this->canonicalManifest());

        $this->assertSame(0, $status, $output);
        $this->assertSame('release manifest: PASS', $output);
    }

    public function test_schema_and_provenance_deviations_are_rejected(): void
    {
        $extraTopLevel = $this->canonicalManifest();
        $extraTopLevel['unexpected'] = true;

        $extraImage = $this->canonicalManifest();
        $extraImage['images']['web'] = $extraImage['images']['nginx'];

        $missingRole = $this->canonicalManifest();
        unset($missingRole['images']['scheduler']);

        $taggedImage = $this->canonicalManifest();
        $taggedImage['images']['fpm'] = 'ghcr.io/polkiujhy/music-map-fpm:latest';

        $foreignRepository = $this->canonicalManifest();
        $foreignRepository['images']['queue'] = 'ghcr.io/example/music-map-queue@sha256:'.str_repeat('c', 64);

        $wrongSource = $this->canonicalManifest();
        $wrongSource['source_sha'] = str_repeat('f', 40);

        $wrongReleaseSource = $this->canonicalManifest();
        $wrongReleaseSource['release_id'] = 'mm-ffffffffffff-123456-1';

        foreach ([
            'extra top-level field' => $extraTopLevel,
            'extra image role' => $extraImage,
            'missing image role' => $missingRole,
            'mutable image tag' => $taggedImage,
            'foreign image repository' => $foreignRepository,
            'source SHA mismatch' => $wrongSource,
            'release ID source mismatch' => $wrongReleaseSource,
        ] as $case => $manifest) {
            [$status] = $this->validate($manifest);

            $this->assertNotSame(0, $status, "The validator accepted: {$case}");
        }
    }

    public function test_malformed_json_is_rejected(): void
    {
        [$status] = $this->validateRaw('{"schema_version":1');

        $this->assertNotSame(0, $status);
    }

    /** @return array<string, mixed> */
    private function canonicalManifest(): array
    {
        return [
            'schema_version' => 1,
            'release_id' => 'mm-0123456789ab-123456-1',
            'source_sha' => self::SOURCE_SHA,
            'images' => [
                'fpm' => 'ghcr.io/polkiujhy/music-map-fpm@sha256:'.str_repeat('a', 64),
                'nginx' => 'ghcr.io/polkiujhy/music-map-nginx@sha256:'.str_repeat('b', 64),
                'queue' => 'ghcr.io/polkiujhy/music-map-queue@sha256:'.str_repeat('c', 64),
                'scheduler' => 'ghcr.io/polkiujhy/music-map-scheduler@sha256:'.str_repeat('d', 64),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{int, string}
     */
    private function validate(array $manifest): array
    {
        $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->validateRaw($json);
    }

    /** @return array{int, string} */
    private function validateRaw(string $contents): array
    {
        $manifest = tempnam(sys_get_temp_dir(), 'music-map-release-');
        $this->assertIsString($manifest);
        file_put_contents($manifest, $contents);

        $command = sprintf(
            'sh %s %s %s 2>&1',
            escapeshellarg(dirname(__DIR__, 2).'/scripts/validate-release-manifest'),
            escapeshellarg($manifest),
            escapeshellarg(self::SOURCE_SHA),
        );
        exec($command, $lines, $status);
        unlink($manifest);

        return [$status, implode("\n", $lines)];
    }
}
