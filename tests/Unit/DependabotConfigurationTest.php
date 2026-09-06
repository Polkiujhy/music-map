<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DependabotConfigurationTest extends TestCase
{
    public function test_configuration_covers_exactly_the_present_ecosystems(): void
    {
        $repository = dirname(__DIR__, 2);
        $updates = $this->dependabotUpdates();
        $actual = array_map(
            static fn (array $update): array => [$update['ecosystem'], $update['directory']],
            $updates,
        );
        $expected = [
            ['github-actions', '/'],
            ['composer', '/'],
            ['npm', '/'],
            ['docker', '/'],
        ];
        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual);
        $this->assertFileExists($repository.'/composer.json');
        $this->assertFileExists($repository.'/composer.lock');
        $this->assertFileExists($repository.'/package.json');
        $this->assertFileExists($repository.'/package-lock.json');
        $this->assertDirectoryExists($repository.'/.github/workflows');
        $this->assertFileExists($repository.'/Dockerfile');
        $this->assertFileDoesNotExist($repository.'/requirements.txt');
    }

    public function test_updates_are_weekly_staggered_and_strictly_bounded(): void
    {
        $updates = $this->dependabotUpdates();
        $scheduleTimes = [];

        foreach ($updates as $update) {
            $this->assertSame('weekly', $update['interval']);
            $this->assertSame('monday', $update['day']);
            $this->assertSame('Europe/London', $update['timezone']);
            $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $update['time']);
            $this->assertGreaterThanOrEqual(1, $update['limit']);
            $this->assertLessThanOrEqual(2, $update['limit']);

            if ($update['ecosystem'] === 'docker') {
                $this->assertSame(1, $update['limit']);
            }

            $scheduleTimes[] = $update['time'];
        }

        $this->assertCount(count($scheduleTimes), array_unique($scheduleTimes));
    }

    public function test_configuration_has_no_grouping_or_automatic_merge(): void
    {
        $source = $this->projectFile('.github/dependabot.yml');

        $this->assertStringNotContainsString('groups:', $source);
        $this->assertStringNotContainsString('multi-ecosystem-groups:', $source);
        $this->assertStringNotContainsString('auto-merge', strtolower($source));
        $this->assertStringNotContainsString('automerge', strtolower($source));
    }

    /**
     * @return list<array{ecosystem: string, directory: string, interval: string, day: string, time: string, timezone: string, limit: int}>
     */
    private function dependabotUpdates(): array
    {
        $source = $this->projectFile('.github/dependabot.yml');
        $matches = [];
        $matched = preg_match_all(
            '/^  - package-ecosystem: (?<ecosystem>[a-z-]+)\n'
            .'    directory: (?<directory>\S+)\n'
            .'    schedule:\n'
            .'      interval: (?<interval>[a-z]+)\n'
            .'      day: (?<day>[a-z]+)\n'
            .'      time: "(?<time>\d{2}:\d{2})"\n'
            .'      timezone: (?<timezone>\S+)\n'
            .'    open-pull-requests-limit: (?<limit>\d+)$/m',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $this->assertSame(substr_count($source, '  - package-ecosystem:'), $matched);

        return array_map(
            static fn (array $match): array => [
                'ecosystem' => $match['ecosystem'],
                'directory' => $match['directory'],
                'interval' => $match['interval'],
                'day' => $match['day'],
                'time' => $match['time'],
                'timezone' => $match['timezone'],
                'limit' => (int) $match['limit'],
            ],
            $matches,
        );
    }

    private function projectFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);

        $this->assertIsString($contents, "Unable to read {$path}");

        return $contents;
    }
}
