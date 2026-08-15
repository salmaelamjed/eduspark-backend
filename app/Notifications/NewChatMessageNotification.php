<?php

namespace App\Notifications;

use App\Models\ChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewChatMessageNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
       public readonly ChatMessage $message
    ){}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    // public function toMail(object $notifiable): MailMessage
    // {
    //     // return (new MailMessage)
    //     //     ->line('The introduction to the notification.')
    //     //     ->action('Notification Action', url('/'))
    //     //     ->line('Thank you for using our application!');
    // }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
     public function toArray($notifiable): array
    {
        return [
            'type' => 'chat_message',
            'room_id' => $this->message->chat_room_id,
            'sender_id' => $this->message->user_id,
            'sender_name' => $this->message->user->name,
            'preview' => \Illuminate\Support\Str::limit($this->message->content, 100),
        ];
    }
}
