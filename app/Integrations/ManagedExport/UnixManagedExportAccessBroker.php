<?php

namespace App\Integrations\ManagedExport;

use App\Integrations\ManagedExport\Contracts\ManagedExportAccessBroker;
use App\Integrations\ManagedExport\Data\ManagedExportAccess;
use Closure;
use DateTimeImmutable;
use JsonException;

final readonly class UnixManagedExportAccessBroker implements ManagedExportAccessBroker
{
    public const PROTOCOL = 'music-map.managed-export.v1';

    private const MAX_RESPONSE_BYTES = 16384;

    private const FAILURE_CATEGORIES = [
        'invalid-request',
        'caller-denied',
        'credential-unavailable',
        'reauthorization-required',
        'scope-mismatch',
        'provider-unavailable',
        'rate-limited',
        'quota-exceeded',
        'rotation-recovery-required',
        'internal-failure',
    ];

    /** @param null|Closure(string): string $transport */
    public function __construct(
        private string $socketPath,
        private ?Closure $transport = null,
    ) {}

    public function acquire(string $provider, string $operationId): ManagedExportAccess
    {
        if (! in_array($provider, ['spotify', 'youtube'], true)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $operationId) !== 1) {
            throw new ManagedExportAccessException('invalid-request');
        }

        try {
            $request = json_encode([
                'protocol' => self::PROTOCOL,
                'provider' => $provider,
                'operation_id' => $operationId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        } catch (JsonException) {
            throw new ManagedExportAccessException('invalid-request');
        }

        $raw = $this->transport instanceof Closure
            ? ($this->transport)($request)
            : $this->exchange($request);

        return $this->parseResponse($raw, $provider, $operationId);
    }

    private function exchange(string $request): string
    {
        if ($this->socketPath === '' || str_contains($this->socketPath, "\0")) {
            throw new ManagedExportAccessException('credential-unavailable');
        }
        $stream = @stream_socket_client(
            'unix://'.$this->socketPath,
            $errorCode,
            $errorMessage,
            2.0,
            STREAM_CLIENT_CONNECT,
        );
        if (! is_resource($stream)) {
            throw new ManagedExportAccessException('credential-unavailable');
        }
        try {
            stream_set_timeout($stream, 5);
            $offset = 0;
            while ($offset < strlen($request)) {
                $written = @fwrite($stream, substr($request, $offset));
                if (! is_int($written) || $written < 1) {
                    throw new ManagedExportAccessException('provider-unavailable', true);
                }
                $offset += $written;
            }
            @stream_socket_shutdown($stream, STREAM_SHUT_WR);
            $response = stream_get_contents($stream, self::MAX_RESPONSE_BYTES + 1);
            $metadata = stream_get_meta_data($stream);
            if (! is_string($response)
                || $response === ''
                || strlen($response) > self::MAX_RESPONSE_BYTES
                || ($metadata['timed_out'] ?? false) === true) {
                throw new ManagedExportAccessException('provider-unavailable', true);
            }

            return $response;
        } finally {
            fclose($stream);
        }
    }

    private function parseResponse(
        string $raw,
        string $provider,
        string $operationId,
    ): ManagedExportAccess {
        if ($raw === ''
            || strlen($raw) > self::MAX_RESPONSE_BYTES
            || str_contains($raw, "\0")
            || ! str_ends_with($raw, "\n")
            || str_ends_with($raw, "\n\n")) {
            throw new ManagedExportAccessException('internal-failure');
        }
        try {
            $response = json_decode(substr($raw, 0, -1), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ManagedExportAccessException('internal-failure');
        }
        if (! is_array($response)
            || ($response['protocol'] ?? null) !== self::PROTOCOL
            || ($response['status'] ?? null) === null) {
            throw new ManagedExportAccessException('internal-failure');
        }

        if ($response['status'] === 'error') {
            $this->throwFailure($response, $provider, $operationId);
        }
        $expected = [
            'access_token', 'expires_at', 'operation_id', 'protocol', 'provider',
            'status', 'token_type',
        ];
        $fields = array_keys($response);
        sort($fields);
        if ($fields !== $expected
            || $response['status'] !== 'ok'
            || $response['provider'] !== $provider
            || $response['operation_id'] !== $operationId
            || $response['token_type'] !== 'Bearer'
            || ! is_string($response['access_token'])
            || preg_match('/^[^\x00-\x20]{1,8192}$/D', $response['access_token']) !== 1
            || ! is_string($response['expires_at'])) {
            throw new ManagedExportAccessException('internal-failure');
        }
        $timestamp = $response['expires_at'];
        if (preg_match(
            '/^(?<date>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?<fraction>\.\d{1,6})?(?<zone>Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D',
            $timestamp,
            $parts,
        ) !== 1) {
            throw new ManagedExportAccessException('internal-failure');
        }
        $normalized = $parts['date']
            .(isset($parts['fraction']) && $parts['fraction'] !== ''
                ? '.'.str_pad(substr($parts['fraction'], 1), 6, '0')
                : '.000000')
            .($parts['zone'] === 'Z' ? '+00:00' : $parts['zone']);
        $expiresAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.uP',
            $normalized,
        );
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($expiresAt === false
            || ($dateErrors !== false
                && ($dateErrors['warning_count'] !== 0 || $dateErrors['error_count'] !== 0))) {
            throw new ManagedExportAccessException('internal-failure');
        }
        if ($expiresAt <= new DateTimeImmutable('now')) {
            throw new ManagedExportAccessException('internal-failure');
        }

        return new ManagedExportAccess(
            $provider,
            $response['access_token'],
            $expiresAt,
            $operationId,
        );
    }

    /** @param array<string, mixed> $response */
    private function throwFailure(array $response, string $provider, string $operationId): never
    {
        $expected = [
            'category', 'operation_id', 'protocol', 'provider', 'retry_after',
            'retryable', 'status',
        ];
        $fields = array_keys($response);
        sort($fields);
        $category = $response['category'] ?? null;
        $retryAfter = $response['retry_after'] ?? null;
        if ($fields !== $expected
            || ! is_string($category)
            || ! in_array($category, self::FAILURE_CATEGORIES, true)
            || ! is_bool($response['retryable'] ?? null)
            || ($retryAfter !== null && (! is_int($retryAfter) || $retryAfter < 0 || $retryAfter > 3600))
            || ! in_array($response['provider'] ?? null, [$provider, null], true)
            || ! in_array($response['operation_id'] ?? null, [$operationId, null], true)) {
            throw new ManagedExportAccessException('internal-failure');
        }

        throw new ManagedExportAccessException(
            $category,
            $response['retryable'],
            $retryAfter,
        );
    }
}
