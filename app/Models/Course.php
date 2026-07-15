<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'thumbnail',
        'level',
        'language',
        'price',
        'is_free',
        'domain_id',
        'teacher_id',
        'status',
         'currency',
        'stripe_product_id',
        'stripe_price_id'
    ];

    protected $casts = [
        'is_free' => 'boolean',
        'level'   => 'string',
        'status'  => 'string',
        'price'   => 'float',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class)
            ->orderBy('order');
    }

    public function lessons()
    {
        return $this->hasManyThrough(
            Lesson::class,
            Module::class,
            'course_id',
            'module_id'
        );
    }


}
