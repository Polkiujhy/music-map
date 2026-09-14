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
use Tests\TestCase;

class YouTubeManagedPlaylistGatewayTest extends TestCase
{
    private const MARKER = '01234567-89ab-cdef-0123-456789abcdef';

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

    public function test_reconcile_rebuilds_ordered_duplicates_and_every_metadata_update_is_complete(): void
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
                $mutations[] = ['delete', $request->data()];
                array_pop($items);

                return Http::response([], 204);
            }
            if ($request->method() === 'POST' && str_contains($url, '/playlistItems?')) {
                $video = $request->data()['snippet']['resourceId']['videoId'];
                $mutations[] = ['insert', $request->data()];
                $items[] = $video;

                return Http::response($this->item($video, count($items) - 1), 201);
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
        $this->assertSame(['delete', 'delete', 'insert', 'insert', 'insert'], array_column(array_slice($mutations, 1), 0));
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
