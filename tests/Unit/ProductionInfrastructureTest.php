<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionInfrastructureTest extends TestCase
{
    public function test_production_environment_example_fails_closed(): void
    {
        $environment = $this->projectFile('.env.example');

        $this->assertStringContainsString("APP_ENV=production\n", $environment);
        $this->assertStringContainsString("APP_DEBUG=false\n", $environment);
        $this->assertStringContainsString("SESSION_SECURE_COOKIE=true\n", $environment);
        $this->assertStringContainsString("DB_HOST=shared-postgres\n", $environment);
        $this->assertStringContainsString("DB_SSLMODE=prefer\n", $environment);
        $this->assertStringContainsString('APP_KEY=__REQUIRED_RUNTIME_SECRET__', $environment);
        $this->assertStringContainsString('DB_PASSWORD=__REQUIRED_RUNTIME_SECRET__', $environment);
        $this->assertDoesNotMatchRegularExpression('/^(APP_KEY|DB_PASSWORD|MAIL_PASSWORD)=$/m', $environment);
    }

    public function test_ci_actions_are_commit_pinned_and_permissions_are_least_privilege(): void
    {
        $workflow = $this->projectFile('.github/workflows/ci.yml');

        $this->assertStringContainsString("permissions:\n  contents: read\n", $workflow);
        $this->assertSame(1, substr_count($workflow, 'packages: write'));
        $this->assertSame(1, substr_count($workflow, 'secrets.GITHUB_TOKEN'));
        $this->assertStringContainsString('gitleaks git --redact --no-banner --log-opts="--all" .', $workflow);
        $this->assertGreaterThan(0, preg_match_all('/^\s*uses:\s*\S+@([0-9a-f]{40})(?:\s|$)/m', $workflow, $matches));

        preg_match_all('/^\s*uses:\s*\S+@(\S+)/m', $workflow, $allActions);
        $this->assertCount(count($allActions[1]), $matches[1]);
    }

    public function test_e11_security_gates_are_exact_read_only_and_blocking(): void
    {
        $workflow = $this->projectFile('.github/workflows/ci.yml');

        $this->assertStringContainsString("permissions:\n  contents: read\n", $workflow);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/.github/workflows/security.yml');
        $this->assertStringContainsString('runs-on: ubuntu-24.04', $workflow);
        $this->assertStringNotContainsString('self-hosted', $workflow);
        preg_match_all('/secrets\.([A-Z0-9_]+)/', $workflow, $workflowSecrets);
        $this->assertSame(['GITHUB_TOKEN'], array_values(array_unique($workflowSecrets[1])));
        $this->assertStringContainsString('fetch-depth: 0', $workflow);
        $this->assertStringContainsString('hadolint Dockerfile', $workflow);
        $this->assertStringContainsString('trivy filesystem --scanners vuln --severity CRITICAL --exit-code 1', $workflow);
        $this->assertStringContainsString('syft "dir:${GITHUB_WORKSPACE}" --output "spdx-json=${SBOM_PATH}"', $workflow);
        $this->assertStringContainsString('uses: actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02 # v4.6.2', $workflow);
        $this->assertStringContainsString('retention-days: 14', $workflow);
        $this->assertStringContainsString('grype "sbom:${SBOM_PATH}" --fail-on critical --output table', $workflow);
        $this->assertStringContainsString('GITLEAKS_VERSION: 8.30.1', $workflow);
        $this->assertStringContainsString('HADOLINT_VERSION: 2.13.1', $workflow);
        $this->assertStringContainsString('SYFT_VERSION: 1.40.0', $workflow);
        $this->assertStringContainsString('GRYPE_VERSION: 0.115.0', $workflow);
        $this->assertStringContainsString('TRIVY_VERSION: 0.70.0', $workflow);
        $this->assertSame(5, preg_match_all('/^  \w+_SHA256: [0-9a-f]{64}$/m', $workflow));
        $this->assertSame(2, substr_count($workflow, 'sha256sum --check --strict'));

        foreach ([
            'continue-on-error',
            '--ignore-unfixed',
            '--exit-code 0',
            '.gitleaksignore',
            '.grype.yaml',
            '.trivyignore',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workflow);
        }

        foreach ([
            '.gitleaksignore',
            '.gitleaks.toml',
            '.grype.yaml',
            '.trivyignore',
            '.trivyignore.yaml',
            '.hadolint.yaml',
            '.hadolint.yml',
        ] as $suppressionFile) {
            $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/'.$suppressionFile);
        }
    }

    public function test_container_contract_is_pinned_non_root_and_health_checked(): void
    {
        $dockerfile = $this->projectFile('Dockerfile');

        $this->assertSame(4, preg_match_all('/^ARG \w+_IMAGE=\S+@sha256:[0-9a-f]{64}$/m', $dockerfile));
        $this->assertSame(4, substr_count($dockerfile, 'HEALTHCHECK '));
        $this->assertGreaterThanOrEqual(1, substr_count($dockerfile, 'USER 82:82'));
        $this->assertStringContainsString("USER 101:101\n", $dockerfile);
        $this->assertStringContainsString('test "$(id -u www-data)" = 82', $dockerfile);
        $this->assertStringContainsString('test "$(id -g www-data)" = 82', $dockerfile);
        $this->assertSame('**', strtok($this->projectFile('.dockerignore'), "\n"));

        $workflow = $this->projectFile('.github/workflows/ci.yml');
        $this->assertSame(4, substr_count($workflow, '--build-arg SOURCE_SHA="${GITHUB_SHA}"'));
        $this->assertStringNotContainsString('music-map-fpm:ci', $workflow);

        foreach (['fpm', 'queue', 'scheduler', 'nginx'] as $role) {
            $entrypoint = $this->projectFile("docker/entrypoints/{$role}.sh");

            $this->assertStringContainsString("set -eu\n", $entrypoint);
            $this->assertStringContainsString('exec ', $entrypoint);
        }

        foreach (['fpm', 'queue', 'scheduler'] as $role) {
            $entrypoint = $this->projectFile("docker/entrypoints/{$role}.sh");
            $this->assertStringContainsString(
                'php /usr/local/libexec/music-map-wait-for-postgres',
                $entrypoint,
            );
        }

        $this->assertStringNotContainsString(
            'music-map-wait-for-postgres',
            $this->projectFile('docker/entrypoints/nginx.sh'),
        );
        $this->assertStringContainsString(
            'COPY docker/entrypoints/wait-for-postgres.php /usr/local/libexec/music-map-wait-for-postgres',
            $dockerfile,
        );
    }

    public function test_release_workflow_builds_prs_and_publishes_only_main_pushes(): void
    {
        $workflow = $this->projectFile('.github/workflows/ci.yml');
        $validator = $this->projectFile('scripts/validate-release-manifest');
        $mainPushCondition = "if: github.event_name == 'push' && github.ref == 'refs/heads/main'";

        $this->assertStringContainsString("on:\n  pull_request:\n  push:\n    branches:\n      - main", $workflow);
        $this->assertStringContainsString('needs: [application, source-security]', $workflow);
        $this->assertSame(4, substr_count($workflow, $mainPushCondition));
        $this->assertStringContainsString(
            'uses: docker/login-action@c94ce9fb468520275223c153574b00df6fe4bcc9 # v3',
            $workflow,
        );
        $this->assertStringContainsString('username: ${{ github.actor }}', $workflow);
        $this->assertStringContainsString('password: ${{ secrets.GITHUB_TOKEN }}', $workflow);

        foreach (['fpm', 'nginx', 'queue', 'scheduler'] as $role) {
            $repository = "ghcr.io/polkiujhy/music-map-{$role}";
            $validatorRepository = str_replace('ghcr.io', 'ghcr[.]io', $repository);

            $this->assertStringContainsString("docker push \"{$repository}:\${GITHUB_SHA}\"", $workflow);
            $this->assertStringContainsString("{$validatorRepository}@sha256:", $validator);
        }

        $this->assertStringContainsString('sh scripts/validate-release-manifest', $workflow);
        $this->assertStringContainsString('name: music-map-release', $workflow);
        $this->assertStringContainsString('path: ${{ runner.temp }}/music-map-release/release.json', $workflow);
        $this->assertStringContainsString('retention-days: 30', $workflow);
        $this->assertStringNotContainsString('attest', strtolower($workflow));
        $this->assertStringNotContainsString('trivy image', strtolower($workflow));
    }

    private function projectFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);

        $this->assertIsString($contents, "Unable to read {$path}");

        return $contents;
    }
}
