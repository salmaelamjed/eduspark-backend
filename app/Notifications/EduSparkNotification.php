<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class EduSparkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly NotificationType $notificationType,
        public readonly array $payload = [],
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => $this->notificationType->value,
            'category' => $this->notificationType->category(),
            'label' => $this->notificationType->label(),
            ...$this->payload,
        ];
    }

    /**
     * Permet de router vers une notif temps réel (broadcast) plus tard
     * sans casser l'existant.
     */
    public function toBroadcast($notifiable)
    {
        return new \Illuminate\Notifications\Messages\BroadcastMessage(
            $this->toArray($notifiable)
        );
    }
}
