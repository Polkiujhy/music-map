<?php

namespace App\Notifications;

use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ExportReviewCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $reviewId,
        public readonly StreamingProvider $provider,
        public readonly ExportReviewStatus $status,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Przegląd eksportu jest zakończony')
            ->markdown('mail.export-review-completed', [
                'platform' => match ($this->provider) {
                    StreamingProvider::Spotify => 'Spotify',
                    StreamingProvider::YouTube => 'YouTube',
                },
                'successful' => $this->status === ExportReviewStatus::Ready,
                'url' => route('bank.index', ['review' => $this->reviewId]),
            ]);
    }
}
