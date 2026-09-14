<?php

namespace App\Notifications;

use App\Enums\ExportOperationStatus;
use App\Enums\StreamingProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ManagedExportCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $operationId,
        public readonly int $reviewId,
        public readonly int $playlistId,
        public readonly StreamingProvider $provider,
        public readonly ExportOperationStatus $status,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Przenoszenie playlisty jest zakończone')
            ->markdown('mail.managed-export-completed', [
                'platform' => $this->provider === StreamingProvider::Spotify ? 'Spotify' : 'YouTube',
                'statusLabel' => match ($this->status) {
                    ExportOperationStatus::Succeeded => 'Przeniesiona — zarządzana przez music-map',
                    ExportOperationStatus::Failed => 'Nie przeniesiono',
                    ExportOperationStatus::PartialFailed,
                    ExportOperationStatus::ManualRecoveryRequired,
                    ExportOperationStatus::RecreateRequired => 'Nie udało się dokończyć przenoszenia',
                    default => 'W trakcie przenoszenia',
                },
                'successful' => $this->status === ExportOperationStatus::Succeeded,
                'url' => route('export-reviews.show', [
                    'playlist' => $this->playlistId,
                    'exportReview' => $this->reviewId,
                ]),
            ]);
    }
}
