<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonBlock extends Model
{
    use HasFactory;

   protected $fillable = [
        'lesson_id', 'type', 'content', 'media_url', 'settings',
        'quiz_data', 'code_data', 'duration_seconds', 'language',
        'order', 'is_preview', 'is_hidden'
    ];

    protected $casts = [
        'settings'   => 'array',
        'quiz_data'  => 'array',
        'code_data'  => 'array',
        'is_preview' => 'boolean',
        'is_hidden'  => 'boolean',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
