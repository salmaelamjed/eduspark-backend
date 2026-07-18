<?php

namespace App\Console\Commands;

use App\Enums\ChatRoomStatus;
use App\Models\ChatRoom;
use Illuminate\Console\Command;

class CloseInactiveChatRooms extends Command
{
    protected $signature = 'chat:close-inactive {--days=7 : Nombre de jours d\'inactivité avant fermeture}';

    protected $description = 'Ferme automatiquement les chat rooms inactives depuis N jours';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $count = ChatRoom::query()
            ->active()
            ->where(function ($q) use ($days) {
                $q->where('last_message_at', '<', now()->subDays($days))
                    ->orWhere(function ($q2) use ($days) {
                        $q2->whereNull('last_message_at')
                            ->where('created_at', '<', now()->subDays($days));
                    });
            })
            ->update(['status' => ChatRoomStatus::CLOSED]);

        $this->info("{$count} room(s) fermée(s) pour inactivité (> {$days} jours).");

        return self::SUCCESS;
    }
}