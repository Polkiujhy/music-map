<?php

namespace App\Models;

use App\Enums\ExportOperationStatus;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use Database\Factories\ExportOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'user_id',
    'export_review_id',
    'playlist_export_id',
    'status',
    'failure_code',
    'attempt_generation',
    'retry_generation',
    'automatic_claim_count',
    'started_at',
    'completed_at',
    'heartbeat_at',
    'job_publication_lease_until',
    'job_published_at',
    'possible_mutation_at',
    'retry_available_at',
    'manual_recovery_requested_at',
    'notification_generation',
    'notification_sent_at',
])]
class ExportOperation extends Model
{
    /** @use HasFactory<ExportOperationFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ExportReview, $this> */
    public function exportReview(): BelongsTo
    {
        return $this->belongsTo(ExportReview::class);
    }

    /** @return BelongsTo<PlaylistExport, $this> */
    public function playlistExport(): BelongsTo
    {
        return $this->belongsTo(PlaylistExport::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $operation): void {
            $operation->id ??= (string) Str::uuid();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ExportOperationStatus::class,
            'failure_code' => ManagedExportFailureCode::class,
            'attempt_generation' => 'integer',
            'retry_generation' => 'integer',
            'automatic_claim_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'job_publication_lease_until' => 'immutable_datetime',
            'job_published_at' => 'immutable_datetime',
            'possible_mutation_at' => 'immutable_datetime',
            'retry_available_at' => 'immutable_datetime',
            'manual_recovery_requested_at' => 'immutable_datetime',
            'notification_generation' => 'integer',
            'notification_sent_at' => 'immutable_datetime',
        ];
    }
}
