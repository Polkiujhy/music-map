<?php

namespace App\Integrations\ManagedAccountExport;

use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\Contracts\ManagedPlaylistGateway;
use InvalidArgumentException;

final readonly class ManagedPlaylistGatewayRegistry
{
    /** @var array<string, ManagedPlaylistGateway> */
    private array $gateways;

    public function __construct(ManagedPlaylistGateway ...$gateways)
    {
        $indexed = [];
        foreach ($gateways as $gateway) {
            $provider = $gateway->provider()->value;
            if (isset($indexed[$provider])) {
                throw new InvalidArgumentException("Duplicate managed playlist gateway for {$provider}.");
            }
            $indexed[$provider] = $gateway;
        }
        $this->gateways = $indexed;
    }

    public function for(StreamingProvider $provider): ManagedPlaylistGateway
    {
        return $this->gateways[$provider->value]
            ?? throw new InvalidArgumentException("No managed playlist gateway for {$provider->value}.");
    }
}
