<?php

namespace App\Models;

use App\Enums\ChatMode;
use App\Enums\ChatRoomStatus;
use App\Enums\SenderType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatRoom extends Model
{
    protected $fillable = [
        'course_id', 'lesson_id', 'student_id', 'teacher_id',
        'mode', 'status', 'last_message_at','student_last_read_at', 'teacher_last_read_at',
    ];

    protected $casts = [
        'mode' => ChatMode::class,
        'status' => ChatRoomStatus::class,
        'last_message_at' => 'datetime',
        'student_last_read_at' => 'datetime',
        'teacher_last_read_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('created_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ChatRoomStatus::ACTIVE);
    }

    public function scopeForTeacher(Builder $query, int $teacherId): Builder
    {
        return $query->where('teacher_id', $teacherId)->where('mode', ChatMode::HUMAN);
    }

    public function isAiMode(): bool
    {
        return $this->mode === ChatMode::AI;
    }

    public function isHumanMode(): bool
    {
        return $this->mode === ChatMode::HUMAN;
    }

    public function isActive(): bool
    {
        return $this->status === ChatRoomStatus::ACTIVE;
    }

    public function hasParticipant(int $userId): bool
    {
        return $this->student_id === $userId || $this->teacher_id === $userId;
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }
     public function unreadCountFor(User $user): int
    {
        $isStudent = $user->id === $this->student_id;
        $lastReadAt = $isStudent ? $this->student_last_read_at : $this->teacher_last_read_at;
        $ownSenderType = $isStudent ? SenderType::STUDENT : SenderType::TEACHER;

        return $this->messages()
            ->where('sender_type', '!=', $ownSenderType->value)
            ->when($lastReadAt, fn ($q) => $q->where('created_at', '>', $lastReadAt))
            ->count();
    }

    public function markAsReadFor(User $user): void
    {
        $isStudent = $user->id === $this->student_id;

        $this->update([
            $isStudent ? 'student_last_read_at' : 'teacher_last_read_at' => now(),
        ]);
    }

}
