<?php

namespace App\Models;

use App\Enums\ChatMode;
use App\Enums\ChatRoomStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatRoom extends Model
{
    protected $fillable = [
        'course_id', 'lesson_id', 'student_id', 'teacher_id',
        'mode', 'status', 'last_message_at',
    ];

    protected $casts = [
        'mode' => ChatMode::class,
        'status' => ChatRoomStatus::class,
        'last_message_at' => 'datetime',
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
}