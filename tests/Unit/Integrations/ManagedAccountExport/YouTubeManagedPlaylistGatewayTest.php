<?php

namespace Tests\Unit\Integrations\ManagedAccountExport;

use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessContext;
use App\Integrations\ManagedAccountExport\Data\ManagedMarkerLookupStatus;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistMetadata;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistReference;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use App\Integrations\ManagedAccountExport\ManagedPlaylistMetadataFactory;
use App\Integrations\ManagedAccountExport\ManagedProviderFailure;
use App\Integrations\ManagedAccountExport\Providers\YouTubeManagedPlaylistGateway;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YouTubeManagedPlaylistGatewayTest extends TestCase
{
    private const MARKER = '01234567-89ab-cdef-0123-456789abcdef';

    public function test_linked_known_target_restores_removed_marker_and_verifies_metadata(): void
    {
        $playlist = $this->playlist();
        $playlist['snippet']['title'] = 'External name';
        $playlist['snippet']['description'] = 'External description';
        Http::fake(function (Request $request) use (&$playlist) {
            if ($request->method() === 'PUT') {
                $playlist = $this->playlist();

                return Http::response($playlist);
            }

            return Http::response(['items' => str_contains($request->url(), '/playlistItems?') ? [] : [$playlist]]);
        });
        $original = $this->access();
        $access = new ManagedAccessContext($original->provider, $original->providerAccountId,
            $original->accessToken, $original->expiresAt, $original->operationId, requireTargetMarker: false);
        $result = (new YouTubeManagedPlaylistGateway)->reconcile($access, $this->reference(), $this->metadata(), []);
        $this->assertNotInstanceOf(ManagedProviderFailure::class, $result);
        $this->assertTrue($result->exact);
        $this->assertSame($this->metadata()->description, $result->snapshot->metadata->description);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_revoked_guard_prevents_create_without_http(): void
    {
        Http::fake();
        $access = $this->access()->withMutationGuard(fn () => ManagedExportFailureCode::AuthenticationRequired);
        $result = (new YouTubeManagedPlaylistGateway)->create($access, $this->metadata());
        $this->assertSame(ManagedExportFailureCode::AuthenticationRequired, $result->code);
        Http::assertNothingSent();
    }

    public function test_create_is_unlisted_and_sends_complete_metadata_only_when_called(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response($this->playlist(), 200)]);

        $reference = (new YouTubeManagedPlaylistGateway)->create($this->access(), $this->metadata());

        $this->assertNotInstanceOf(ManagedProviderFailure::class, $reference);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame([
                'snippet' => ['title' => $this->metadata()->title, 'description' => $this->metadata()->description],
                'status' => ['privacyStatus' => 'unlisted'],
            ], $request->data());

            return true;
        });
    }

    public function test_provider_daily_quota_exhaustion_is_not_retryable(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response([
                'error' => ['errors' => [['reason' => 'dailyLimitExceeded']]],
            ], 403),
        ]);

        $failure = (new YouTubeManagedPlaylistGateway)->create($this->access(), $this->metadata());

        $this->assertInstanceOf(ManagedProviderFailure::class, $failure);
        $this->assertSame(ManagedExportFailureCode::QuotaExceeded, $failure->code);
        $this->assertFalse($failure->retryable);
        $this->assertNull($failure->retryAfter);
    }

    public function test_marker_recovery_requires_two_identical_complete_paginated_reads(): void
    {
        Http::fakeSequence('www.googleapis.com/*')
            ->push(['items' => [], 'nextPageToken' => 'next'])
            ->push(['items' => [$this->playlist()]])
            ->push(['items' => [], 'nextPageToken' => 'next'])
            ->push(['items' => [$this->playlist()]]);

        $lookup = (new YouTubeManagedPlaylistGateway)->findByMarker($this->access(), self::MARKER);

        $this->assertNotInstanceOf(ManagedProviderFailure::class, $lookup);
        $this->assertSame(ManagedMarkerLookupStatus::One, $lookup->status);
        Http::assertSentCount(4);
    }

    public function test_changed_observation_is_inconclusive_and_never_creates(): void
    {
        Http::fakeSequence('www.googleapis.com/*')
            ->push(['items' => []])
            ->push(['items' => [$this->playlist()]]);

        $lookup = (new YouTubeManagedPlaylistGateway)->findByMarker($this->access(), self::MARKER);

        $this->assertSame(ManagedMarkerLookupStatus::Inconclusive, $lookup->status);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_reconcile_inserts_only_the_missing_duplicate_and_metadata_is_complete(): void
    {
        $items = ['video-a', 'video-b'];
        $mutations = [];
        Http::fake(function (Request $request) use (&$items, &$mutations) {
            $url = $request->url();
            if ($request->method() === 'GET' && str_contains($url, '/playlists?')) {
                return Http::response(['items' => [$this->playlist()]]);
            }
            if ($request->method() === 'GET' && str_contains($url, '/playlistItems?')) {
                return Http::response(['items' => array_map(
                    fn (string $video, int $position): array => $this->item($video, $position),
                    $items,
                    array_keys($items),
                )]);
            }
            if ($request->method() === 'PUT' && str_contains($url, '/playlists?')) {
                $mutations[] = ['metadata', $request->data()];

                return Http::response($this->playlist());
            }
            if ($request->method() === 'DELETE') {
                $mutations[] = ['delete', $request->url(), $request->data()];
                array_pop($items);

                return Http::response([], 204);
            }
            if ($request->method() === 'POST' && str_contains($url, '/playlistItems?')) {
                $video = $request->data()['snippet']['resourceId']['videoId'];
                $mutations[] = ['insert', $request->data()];
                array_splice($items, $request->data()['snippet']['position'], 0, [$video]);

                return Http::response(['id' => 'new-occurrence'], 201);
            }

            return Http::response([], 500);
        });

        $desired = ['video-a', 'video-a', 'video-b'];
        $result = (new YouTubeManagedPlaylistGateway)->reconcile($this->access(), $this->reference(), $this->metadata(), $desired);

        $this->assertNotInstanceOf(ManagedProviderFailure::class, $result);
        $this->assertTrue($result->exact);
        $this->assertSame($desired, $items);
        $this->assertSame([
            'id' => 'PL_target',
            'snippet' => ['title' => $this->metadata()->title, 'description' => $this->metadata()->description],
            'status' => ['privacyStatus' => 'unlisted'],
        ], $mutations[0][1]);
        $this->assertSame(['insert'], array_column(array_slice($mutations, 1), 0));
        $this->assertSame(1, $mutations[1][1]['snippet']['position']);
    }

    public static function mutationCases(): array
    {
        return [
            'append' => [['a', 'b'], ['a', 'b', 'c'], ['insert']],
            'duplicate' => [['a', 'b'], ['a', 'a', 'b'], ['insert']],
            'rotate' => [['a', 'b', 'c'], ['b', 'c', 'a'], ['move']],
            'duplicate rotation' => [['a', 'a', 'b'], ['b', 'a', 'a'], ['move']],
            'remove excess duplicate' => [['a', 'a', 'b'], ['a', 'b'], ['delete']],
            'move past pending insertion' => [['a', 'b', 'a', 'c'], ['a', 'c', 'd', 'a'], ['delete', 'move', 'insert']],
            'already exact' => [['a', 'a', 'b'], ['a', 'a', 'b'], []],
        ];
    }

    #[DataProvider('mutationCases')]
    public function test_reconciliation_preserves_occurrences_and_avoids_unnecessary_mutations(array $initial, array $desired, array $expected): void
    {
        $state = $this->providerState($initial);
        $mutations = [];
        $this->fakeMutableProvider($state, $mutations);
        $result = (new YouTubeManagedPlaylistGateway)->reconcile($this->access(), $this->reference(), $this->metadata(), $desired);

        $this->assertNotInstanceOf(ManagedProviderFailure::class, $result);
        $this->assertTrue($result->exact);
        $this->assertSame($desired, array_column($state, 'video'));
        $this->assertSame($expected, $mutations);
    }

    public function test_retry_after_lost_insert_response_reads_current_state_without_inserting_twice(): void
    {
        $state = $this->providerState(['a']);
        $mutations = [];
        $this->fakeMutableProvider($state, $mutations, loseInsertResponse: true);
        $gateway = new YouTubeManagedPlaylistGateway;
        $first = $gateway->reconcile($this->access(), $this->reference(), $this->metadata(), ['a', 'b']);
        $this->assertSame(ManagedExportFailureCode::AmbiguousMutation, $first->code);

        $retry = $gateway->reconcile($this->access(), $this->reference(), $this->metadata(), ['a', 'b']);
        $this->assertTrue($retry->exact);
        $this->assertSame(['insert'], $mutations);
        $this->assertSame(['a', 'b'], array_column($state, 'video'));
    }

    public function test_revocation_before_reorder_prevents_the_item_write(): void
    {
        $state = $this->providerState(['a', 'b']);
        $mutations = [];
        $this->fakeMutableProvider($state, $mutations);
        $checks = 0;
        $access = $this->access()->withMutationGuard(function () use (&$checks): ?ManagedExportFailureCode {
            return ++$checks > 1 ? ManagedExportFailureCode::AuthenticationRequired : null;
        });
        $result = (new YouTubeManagedPlaylistGateway)->reconcile($access, $this->reference(), $this->metadata(), ['b', 'a']);
        $this->assertSame(ManagedExportFailureCode::AuthenticationRequired, $result->code);
        $this->assertSame([], $mutations);
        $this->assertSame(['a', 'b'], array_column($state, 'video'));
    }

    private function providerState(array $videos): array
    {
        return array_map(fn ($video, $index): array => ['id' => 'existing-'.$index, 'video' => $video], $videos, array_keys($videos));
    }

    private function fakeMutableProvider(array &$state, array &$mutations, bool $loseInsertResponse = false): void
    {
        $nextId = 0;
        Http::fake(function (Request $request) use (&$state, &$mutations, &$nextId, &$loseInsertResponse) {
            if (str_contains($request->url(), '/playlists?')) {
                return Http::response($request->method() === 'GET' ? ['items' => [$this->playlist()]] : $this->playlist());
            }
            if ($request->method() === 'GET') {
                return Http::response(['items' => array_map(function ($item, $position): array {
                    return array_replace($this->item($item['video'], $position), ['id' => $item['id']]);
                }, $state, array_keys($state))]);
            }
            if ($request->method() === 'POST') {
                $mutations[] = 'insert';
                $item = ['id' => 'inserted-'.++$nextId, 'video' => $request['snippet']['resourceId']['videoId']];
                array_splice($state, $request['snippet']['position'], 0, [$item]);
                if ($loseInsertResponse) {
                    $loseInsertResponse = false;

                    return Http::response([], 503);
                }

                return Http::response(['id' => $item['id']], 201);
            }
            if ($request->method() === 'DELETE') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $index = array_search($query['id'], array_column($state, 'id'), true);
                $this->assertNotFalse($index);
                array_splice($state, $index, 1);
                $mutations[] = 'delete';

                return Http::response([], 204);
            }
            $index = array_search($request['id'], array_column($state, 'id'), true);
            $this->assertNotFalse($index);
            $item = $state[$index];
            array_splice($state, $index, 1);
            $this->assertLessThanOrEqual(count($state), $request['snippet']['position']);
            array_splice($state, $request['snippet']['position'], 0, [$item]);
            $mutations[] = 'move';

            return Http::response(['id' => $item['id']]);
        });
    }

    public function test_failed_item_mutation_uses_closed_taxonomy_without_provider_body(): void
    {
        Http::fakeSequence('www.googleapis.com/*')
            ->push(['items' => [$this->playlist()]])
            ->push(['items' => [$this->item('old', 0)]])
            ->push(['items' => [$this->playlist()]])
            ->push(['items' => [$this->item('old', 0)]])
            ->push($this->playlist())
            ->push(['error' => ['message' => 'secret-canary']], 503);

        $failure = (new YouTubeManagedPlaylistGateway)->reconcile($this->access(), $this->reference(), $this->metadata(), []);

        $this->assertInstanceOf(ManagedProviderFailure::class, $failure);
        $this->assertSame(ManagedExportFailureCode::AmbiguousMutation, $failure->code);
        $this->assertStringNotContainsString('secret-canary', serialize($failure));
    }

    public function test_item_not_found_does_not_report_the_target_playlist_as_missing(): void
    {
        Http::fakeSequence('www.googleapis.com/*')
            ->push(['items' => [$this->playlist()]])
            ->push(['items' => []])
            ->push(['items' => [$this->playlist()]])
            ->push(['items' => []])
            ->push($this->playlist())
            ->push(['error' => ['errors' => [['reason' => 'videoNotFound']]]], 404);

        $failure = (new YouTubeManagedPlaylistGateway)->reconcile(
            $this->access(),
            $this->reference(),
            $this->metadata(),
            ['missing-video'],
        );

        $this->assertInstanceOf(ManagedProviderFailure::class, $failure);
        $this->assertSame(ManagedExportFailureCode::ItemRejected, $failure->code);
        $this->assertNotSame(ManagedExportFailureCode::TargetMissing, $failure->code);
    }

    private function access(): ManagedAccessContext
    {
        return new ManagedAccessContext(StreamingProvider::YouTube, 'channel-owner', 'token-canary', new DateTimeImmutable('+5 minutes'), '123e4567-e89b-42d3-a456-426614174000');
    }

    private function metadata(): ManagedPlaylistMetadata
    {
        return (new ManagedPlaylistMetadataFactory)->make(StreamingProvider::YouTube, 'Bank title', 'Description', self::MARKER);
    }

    private function reference(): ManagedPlaylistReference
    {
        return new ManagedPlaylistReference(StreamingProvider::YouTube, 'PL_target', 'https://www.youtube.com/playlist?list=PL_target', 'channel-owner');
    }

    private function playlist(): array
    {
        return [
            'id' => 'PL_target',
            'snippet' => [
                'channelId' => 'channel-owner',
                'title' => $this->metadata()->title,
                'description' => $this->metadata()->description,
            ],
            'status' => ['privacyStatus' => 'unlisted'],
            'etag' => 'revision',
        ];
    }

    private function item(string $videoId, int $position): array
    {
        return [
            'id' => 'occurrence-'.$position,
            'snippet' => [
                'position' => $position,
                'resourceId' => ['kind' => 'youtube#video', 'videoId' => $videoId],
            ],
        ];
    }
}
