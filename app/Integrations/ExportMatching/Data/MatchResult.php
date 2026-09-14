<?php

namespace App\Integrations\ExportMatching\Data;

use App\Enums\ExportMatchStatus;
use InvalidArgumentException;

final readonly class MatchResult
{
    private function __construct(
        public ExportMatchStatus $status,
        public ?CatalogCandidate $candidate,
    ) {
        if ($status === ExportMatchStatus::Unavailable && $candidate !== null) {
            throw new InvalidArgumentException('Unavailable results cannot contain a candidate.');
        }

        if ($status !== ExportMatchStatus::Unavailable && $candidate === null) {
            throw new InvalidArgumentException('Matched results require a candidate.');
        }
    }

    public static function matched(CatalogCandidate $candidate): self
    {
        return new self(ExportMatchStatus::Matched, $candidate);
    }

    public static function suspicious(CatalogCandidate $candidate): self
    {
        return new self(ExportMatchStatus::Suspicious, $candidate);
    }

    public static function unavailable(): self
    {
        return new self(ExportMatchStatus::Unavailable, null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['status' => $this->status->value, 'candidate' => $this->candidate?->toArray()];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        $status = ExportMatchStatus::from((string) $value['status']);
        $candidate = is_array($value['candidate'] ?? null)
            ? CatalogCandidate::fromArray($value['candidate'])
            : null;

        return new self($status, $candidate);
    }
}
