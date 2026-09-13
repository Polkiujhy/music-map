<?php

namespace Tests\Unit\Integrations\PlatformAccess;

use App\Integrations\PlatformAccess\PlatformAccessProtocol;
use App\Integrations\PlatformAccess\ProbeFailure;
use App\Integrations\PlatformAccess\ProbeInvocation;
use App\Integrations\PlatformAccess\TechnicalConfiguration;
use App\Integrations\PlatformAccess\TesterSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProbeInputTest extends TestCase
{
    public function test_exact_raw_invocation_is_accepted_in_any_option_order(): void
    {
        $invocation = ProbeInvocation::fromRawTokens([
            'platform-access:probe',
            '--no-interaction',
            '--principal=tester',
            '--format=json',
            '--provider=youtube',
            '--write',
            '--no-ansi',
        ]);

        $this->assertInstanceOf(ProbeInvocation::class, $invocation);
        $this->assertSame('youtube', $invocation->provider);
        $this->assertSame('tester', $invocation->principal);
    }

    #[DataProvider('invalidInvocations')]
    public function test_raw_invocation_is_closed(array $tokens): void
    {
        $result = ProbeInvocation::fromRawTokens($tokens);

        $this->assertInstanceOf(ProbeFailure::class, $result);
        $this->assertSame('invalid-invocation', $result->category);
        $this->assertSame(2, $result->exitCode());
    }

    public static function invalidInvocations(): array
    {
        $valid = [
            'platform-access:probe',
            '--provider=spotify',
            '--principal=technical',
            '--write',
            '--format=json',
            '--no-ansi',
            '--no-interaction',
        ];

        return [
            'missing option' => [array_values(array_diff($valid, ['--write']))],
            'duplicate option' => [[...$valid, '--write']],
            'separated value' => [[
                'platform-access:probe', '--provider', 'spotify', '--principal=technical',
                '--write', '--format=json', '--no-ansi', '--no-interaction',
            ]],
            'unknown option' => [[...$valid, '--unknown']],
            'unsupported provider' => [[
                'platform-access:probe', '--provider=tidal', '--principal=technical',
                '--write', '--format=json', '--no-ansi', '--no-interaction',
            ]],
            'positional argument' => [[...$valid, 'extra']],
            'token before command' => [[
                'extra', 'platform-access:probe', '--provider=spotify', '--principal=technical',
                '--write', '--format=json', '--no-ansi', '--no-interaction',
            ]],
        ];
    }

    public function test_valid_technical_configuration_requires_matching_accounts_and_exact_scopes(): void
    {
        $configuration = TechnicalConfiguration::fromArray('spotify', $this->technicalValues());

        $this->assertInstanceOf(TechnicalConfiguration::class, $configuration);
        $this->assertSame('account-1', $configuration->expectedAccountId);
        $this->assertSame(PlatformAccessProtocol::SCOPES['spotify'], $configuration->scopes);
    }

    public function test_youtube_technical_configuration_requires_its_exact_scope(): void
    {
        $values = [
            ...$this->technicalValues(),
            'scopes' => PlatformAccessProtocol::SCOPES['youtube'][0],
        ];

        $accepted = TechnicalConfiguration::fromArray('youtube', $values);
        $rejected = TechnicalConfiguration::fromArray('youtube', [
            ...$values,
            'scopes' => $values['scopes'].' extra-scope',
        ]);

        $this->assertInstanceOf(TechnicalConfiguration::class, $accepted);
        $this->assertSame(PlatformAccessProtocol::SCOPES['youtube'], $accepted->scopes);
        $this->assertInstanceOf(ProbeFailure::class, $rejected);
        $this->assertSame('invalid-configuration', $rejected->category);
    }

    #[DataProvider('invalidTechnicalConfiguration')]
    public function test_invalid_technical_configuration_is_rejected(array $overrides): void
    {
        $configuration = TechnicalConfiguration::fromArray(
            'spotify',
            [...$this->technicalValues(), ...$overrides],
        );

        $this->assertInstanceOf(ProbeFailure::class, $configuration);
        $this->assertSame('invalid-configuration', $configuration->category);
    }

    public static function invalidTechnicalConfiguration(): array
    {
        return [
            'missing' => [['client_id' => null]],
            'empty' => [['client_secret' => '']],
            'secret placeholder' => [['refresh_token' => '__REQUIRED_RUNTIME_SECRET__']],
            'value placeholder' => [['account_id' => '__REQUIRED_RUNTIME_VALUE__']],
            'account mismatch' => [['account_id' => 'account-2']],
            'scope missing' => [['scopes' => 'playlist-read-private user-read-private']],
            'scope additional' => [['scopes' => 'playlist-modify-private playlist-read-private user-read-private user-read-email']],
        ];
    }

    public function test_valid_closed_tester_session_is_accepted_for_each_provider(): void
    {
        $spotify = TesterSession::fromJson('spotify', json_encode($this->sessionValues('spotify'), JSON_THROW_ON_ERROR));
        $youtube = TesterSession::fromJson('youtube', json_encode($this->sessionValues('youtube'), JSON_THROW_ON_ERROR));

        $this->assertInstanceOf(TesterSession::class, $spotify);
        $this->assertInstanceOf(TesterSession::class, $youtube);
        $this->assertCount(3, $spotify->itemUris);
        $this->assertCount(3, $youtube->itemUris);
    }

    public function test_json_schema_lengths_count_utf8_characters_instead_of_bytes(): void
    {
        $values = $this->sessionValues('youtube');
        $values['client_secret'] = str_repeat('ą', 8192);
        $accepted = TesterSession::fromJson('youtube', json_encode($values, JSON_THROW_ON_ERROR));

        $values['client_secret'] .= 'ą';
        $rejected = TesterSession::fromJson('youtube', json_encode($values, JSON_THROW_ON_ERROR));

        $this->assertInstanceOf(TesterSession::class, $accepted);
        $this->assertInstanceOf(ProbeFailure::class, $rejected);
        $this->assertSame('invalid-session', $rejected->category);
    }

    public function test_session_file_enforces_the_published_document_size_limit(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'music-map-session-');
        $this->assertIsString($path);

        try {
            $json = json_encode($this->sessionValues('youtube'), JSON_THROW_ON_ERROR);
            $atLimit = $json.str_repeat(' ', PlatformAccessProtocol::MAX_SESSION_DOCUMENT_BYTES - strlen($json));
            file_put_contents($path, $atLimit);

            $this->assertInstanceOf(TesterSession::class, TesterSession::fromFile('youtube', $path));

            file_put_contents($path, $atLimit.' ');
            $rejected = TesterSession::fromFile('youtube', $path);

            $this->assertInstanceOf(ProbeFailure::class, $rejected);
            $this->assertSame('invalid-session', $rejected->category);
        } finally {
            @unlink($path);
        }
    }

    public function test_missing_or_unreadable_session_file_is_rejected(): void
    {
        $missing = sys_get_temp_dir().'/music-map-missing-session-'.bin2hex(random_bytes(8));

        foreach ([$missing, sys_get_temp_dir()] as $path) {
            $result = TesterSession::fromFile('spotify', $path);

            $this->assertInstanceOf(ProbeFailure::class, $result);
            $this->assertSame('invalid-session', $result->category);
        }
    }

    #[DataProvider('invalidSessionFieldBoundaries')]
    public function test_session_field_boundaries_are_closed(array $overrides): void
    {
        $values = [...$this->sessionValues('youtube'), ...$overrides];
        $result = TesterSession::fromJson('youtube', json_encode($values, JSON_THROW_ON_ERROR));

        $this->assertInstanceOf(ProbeFailure::class, $result);
        $this->assertSame('invalid-session', $result->category);
    }

    public static function invalidSessionFieldBoundaries(): array
    {
        return [
            'empty client id' => [['client_id' => '']],
            'client id over 8192 characters' => [['client_id' => str_repeat('a', 8193)]],
            'control character in client secret' => [['client_secret' => "secret\tvalue"]],
            'expected account over 255 characters' => [['expected_account_id' => str_repeat('a', 256)]],
            'playlist id over 255 characters' => [['playlist_id' => str_repeat('a', 256)]],
            'invalid YouTube item id' => [['item_uris' => ['AAAAAAAAAA!', 'BBBBBBBBBBB', 'CCCCCCCCCCC']]],
        ];
    }

    #[DataProvider('invalidTesterSessions')]
    public function test_tester_session_rejects_invalid_or_open_documents(callable $document): void
    {
        $values = $document($this->sessionValues('spotify'));
        $json = is_string($values) ? $values : json_encode($values, JSON_THROW_ON_ERROR);
        $session = TesterSession::fromJson('spotify', $json);

        $this->assertInstanceOf(ProbeFailure::class, $session);
        $this->assertSame('invalid-session', $session->category);
        $this->assertSame(2, $session->exitCode());
    }

    public static function invalidTesterSessions(): array
    {
        return [
            'invalid JSON' => [static fn (array $values): string => '{'],
            'missing field' => [static function (array $values): array {
                unset($values['playlist_id']);

                return $values;
            }],
            'additional field' => [static fn (array $values): array => [...$values, 'extra' => true]],
            'protocol mismatch' => [static fn (array $values): array => [...$values, 'protocol' => 'v2']],
            'provider mismatch' => [static fn (array $values): array => [...$values, 'provider' => 'youtube']],
            'principal mismatch' => [static fn (array $values): array => [...$values, 'principal' => 'technical']],
            'control in secret' => [static fn (array $values): array => [...$values, 'refresh_token' => "secret\nvalue"]],
            'too many items' => [static fn (array $values): array => [...$values, 'item_uris' => [...$values['item_uris'], 'spotify:track:DDDDDDDDDDDDDDDDDDDDDD']]],
            'non-canonical item' => [static fn (array $values): array => [...$values, 'item_uris' => ['track:bad', ...array_slice($values['item_uris'], 1)]]],
        ];
    }

    private function technicalValues(): array
    {
        return [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'refresh_token' => 'refresh-token',
            'expected_account_id' => 'account-1',
            'account_id' => 'account-1',
            'scopes' => 'user-read-private playlist-read-private playlist-modify-private',
        ];
    }

    private function sessionValues(string $provider): array
    {
        return [
            'protocol' => PlatformAccessProtocol::VERSION,
            'provider' => $provider,
            'principal' => 'tester',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'refresh_token' => 'refresh-token',
            'expected_account_id' => 'account-id',
            'item_uris' => $provider === 'spotify'
                ? [
                    'spotify:track:AAAAAAAAAAAAAAAAAAAAAA',
                    'spotify:track:BBBBBBBBBBBBBBBBBBBBBB',
                    'spotify:track:CCCCCCCCCCCCCCCCCCCCCC',
                ]
                : ['AAAAAAAAAAA', 'BBBBBBBBBBB', 'CCCCCCCCCCC'],
            'playlist_id' => 'playlist-id',
        ];
    }
}
