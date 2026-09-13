<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SourceContractTest extends TestCase
{
    public function test_required_source_manifest_contains_existing_stable_paths_and_all_workflows(): void
    {
        $repository = dirname(__DIR__, 2);
        $requiredPaths = $this->requiredPaths($repository);

        $this->assertSame(
            $requiredPaths,
            array_values(array_unique($requiredPaths)),
            'The required source manifest must not contain duplicate paths.',
        );

        foreach ($requiredPaths as $path) {
            $this->assertFileExists($repository.'/'.$path, "Required source path is stale: {$path}");
        }

        $changeArtifacts = array_filter(
            $requiredPaths,
            static fn (string $path): bool => str_starts_with($path, 'context/changes/')
                && $path !== 'context/changes/README.md',
        );
        $this->assertSame(
            [],
            array_values($changeArtifacts),
            'Lifecycle-managed change artifacts must not be pinned in the stable source manifest.',
        );

        $manifestWorkflows = array_values(array_filter(
            $requiredPaths,
            static fn (string $path): bool => str_starts_with($path, '.github/workflows/'),
        ));
        $workflowFiles = glob($repository.'/.github/workflows/*.{yml,yaml}', GLOB_BRACE);

        $this->assertIsArray($workflowFiles);

        $trackedWorkflows = array_map(
            static fn (string $path): string => substr($path, strlen($repository) + 1),
            $workflowFiles,
        );
        sort($manifestWorkflows);
        sort($trackedWorkflows);

        $this->assertSame(
            $trackedWorkflows,
            $manifestWorkflows,
            'Every workflow must be covered by the required source manifest.',
        );
    }

    public function test_worktree_contract_rejects_php_source_missing_from_manifest(): void
    {
        $repository = dirname(__DIR__, 2);
        $unexpectedPath = $repository.'/app/UnmanifestedSourceContractProbe.php';
        $this->assertFileDoesNotExist($unexpectedPath);

        try {
            file_put_contents($unexpectedPath, "<?php\n");
            exec(
                'cd '.escapeshellarg($repository).' && sh scripts/verify-source-contract --worktree 2>&1',
                $output,
                $exitCode,
            );
        } finally {
            if (is_file($unexpectedPath)) {
                unlink($unexpectedPath);
            }
        }

        $this->assertSame(1, $exitCode);
        $this->assertContains(
            'PHP source path is missing from required_paths: app/UnmanifestedSourceContractProbe.php',
            $output,
        );
    }

    /** @return list<string> */
    private function requiredPaths(string $repository): array
    {
        $contract = file_get_contents($repository.'/scripts/verify-source-contract');

        $this->assertIsString($contract);
        $this->assertSame(
            1,
            preg_match("/required_paths='(?<paths>.*?)'\n\nprintf/s", $contract, $matches),
            'Unable to parse the required source manifest.',
        );

        return explode("\n", trim($matches['paths']));
    }
}
