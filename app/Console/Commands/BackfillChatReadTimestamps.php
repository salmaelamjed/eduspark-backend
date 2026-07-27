<?php

namespace App\Console\Commands;

use App\Models\ChatRoom;
use Illuminate\Console\Command;

class BackfillChatReadTimestamps extends Command
{
    protected $signature = 'chat:backfill-read-timestamps';

    protected $description = 'Initialise les timestamps de lecture des rooms existantes pour éviter un faux backlog de messages non lus';

    public function handle(): int
    {
        $rooms = ChatRoom::query()
            ->whereNull('student_last_read_at')
            ->orWhereNull('teacher_last_read_at')
            ->get();

        $count = 0;

        foreach ($rooms as $room) {
            $room->update([
                'student_last_read_at' => $room->student_last_read_at ?? $room->last_message_at ?? now(),
                'teacher_last_read_at' => $room->teacher_last_read_at ?? $room->last_message_at ?? now(),
            ]);
            $count++;
        }

        $this->info("{$count} room(s) mise(s) à jour.");

        return self::SUCCESS;
    }
}
