<?php

namespace App\Notifications;

use App\Enums\ExportDestinationType;
use App\Enums\ExportOperationStatus;
use App\Enums\StreamingProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ExportOperationCompleted extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $operationId,
        public readonly int $playlistId,
        public readonly StreamingProvider $provider,
        public readonly ExportDestinationType $destinationType,
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
            ->subject('Eksport playlisty jest zakończony')
            ->markdown('mail.export-operation-completed', [
                'platform' => match ($this->provider) {
                    StreamingProvider::Spotify => 'Spotify',
                    StreamingProvider::YouTube => 'YouTube',
                },
                'successful' => $this->status === ExportOperationStatus::Transferred,
                'owner' => $this->destinationType === ExportDestinationType::Linked
                    ? 'na Twoim koncie'
                    : 'zarządzana przez music-map',
                'url' => route('export-operations.show', [
                    'playlist' => $this->playlistId,
                    'exportOperation' => $this->operationId,
                ]),
            ]);
    }
}
