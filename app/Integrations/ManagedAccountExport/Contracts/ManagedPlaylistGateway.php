<?php

namespace App\Integrations\ManagedAccountExport\Contracts;

use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessContext;
use App\Integrations\ManagedAccountExport\Data\ManagedMarkerLookup;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistMetadata;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistReconciliationResult;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistReference;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistSnapshot;
use App\Integrations\ManagedAccountExport\ManagedProviderFailure;

interface ManagedPlaylistGateway
{
    public function provider(): StreamingProvider;

    public function findByMarker(ManagedAccessContext $access, string $marker): ManagedMarkerLookup|ManagedProviderFailure;

    public function create(ManagedAccessContext $access, ManagedPlaylistMetadata $metadata): ManagedPlaylistReference|ManagedProviderFailure;

    public function inspect(ManagedAccessContext $access, ManagedPlaylistReference $reference): ManagedPlaylistSnapshot|ManagedProviderFailure;

    /** @param list<string> $catalogItems */
    public function reconcile(
        ManagedAccessContext $access,
        ManagedPlaylistReference $reference,
        ManagedPlaylistMetadata $metadata,
        array $catalogItems,
    ): ManagedPlaylistReconciliationResult|ManagedProviderFailure;
}
