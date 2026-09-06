<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class RuntimeFilesystemContractTest extends TestCase
{
    public function test_image_packages_manifest_and_runtime_cache_are_separate(): void
    {
        $root = dirname(__DIR__, 2);
        $dockerfile = file_get_contents($root.'/Dockerfile');
        $helper = file_get_contents($root.'/docker/entrypoints/bootstrap-runtime.sh');

        $this->assertIsString($dockerfile);
        $this->assertIsString($helper);
        $this->assertStringContainsString(
            'tar -C bootstrap/cache -cf /usr/local/share/music-map/package-manifest.tar packages.php services.php',
            $dockerfile,
        );
        $this->assertStringContainsString('rm -f bootstrap/cache/*.php', $dockerfile);
        $this->assertStringContainsString('tar -xf "$manifest" -C "$cache"', $helper);
        $this->assertStringContainsString('php artisan config:cache --no-ansi >/dev/null 2>&1', $helper);
        $this->assertStringContainsString('/var/www/html/storage/framework/sessions', $helper);
        $this->assertStringContainsString('/var/www/html/storage/framework/views', $helper);
        $this->assertStringNotContainsString('env', $helper);
        $this->assertStringNotContainsString('printenv', $helper);
    }

    public function test_every_php_role_uses_the_common_bootstrap_before_database_wait(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['fpm', 'queue', 'scheduler'] as $role) {
            $entrypoint = file_get_contents($root."/docker/entrypoints/{$role}.sh");

            $this->assertIsString($entrypoint);
            $bootstrap = strpos($entrypoint, 'music_map_bootstrap_runtime');
            $database = strpos($entrypoint, 'music-map-wait-for-postgres');
            $this->assertIsInt($bootstrap);
            $this->assertIsInt($database);
            $this->assertLessThan($database, $bootstrap);
        }
    }
}
