<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttempt extends Model
{
    protected $table = 'quiz_attempts';

    protected $fillable = [
        'user_id',
        'block_id',
        'score',
        'score_percentage',
        'total_questions',
        'correct_answers',
        'is_passed',
        'answers',
        'started_at',
        'completed_at',
        'duration_seconds'
    ];

    protected $casts = [
        'answers' => 'array',
        'score' => 'integer',
        'score_percentage' => 'float',
        'total_questions' => 'integer',
        'correct_answers' => 'integer',
        'is_passed' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(LessonBlock::class, 'block_id');
    }
}