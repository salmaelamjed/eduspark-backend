<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lesson extends Model
{
    use HasFactory;

    protected $fillable = [
        'module_id',
        'title',
        'slug',
        'order',
        'is_preview',
    ];

    protected $casts = [
        'is_preview' => 'boolean',
        'order'      => 'integer',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(LessonBlock::class)
            ->orderBy('order');
    }

    public function course()
    {
        return $this->module->course();
    }
}