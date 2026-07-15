<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseEnrollment extends Model
{
    use HasFactory;

    /**
     * Les attributs qui sont assignables en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'course_id',
        'student_id',
        'purchase_id',
        'enrolled_at',
    ];

    /**
     * Les attributs qui doivent être castés.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'enrolled_at' => 'datetime',
    ];

    /**
     * Relation avec le cours.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Relation avec l'étudiant (utilisateur).
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * Relation avec l'achat.
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(CoursePurchase::class, 'purchase_id');
    }
}
