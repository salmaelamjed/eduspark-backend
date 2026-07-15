<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoursePurchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'student_id',
        'teacher_id',
        'stripe_payment_intent_id',
        'stripe_transfer_id',
        'amount_total',
        'commission_amount',
        'teacher_amount',
        'currency',
        'status',
        'purchased_at',
        'refunded_at'
    ];

    protected $casts = [
        'amount_total' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'teacher_amount' => 'decimal:2',
        'purchased_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function commission()
    {
        return $this->hasOne(Commission::class);
    }

    public function markAsCompleted()
    {
        $this->update([
            'status' => 'completed',
            'purchased_at' => now()
        ]);

        // Mettre à jour les statistiques du teacher
        $this->teacher->increment('total_earnings', $this->teacher_amount);
        $this->teacher->increment('total_commission_paid', $this->commission_amount);
    }

    public function markAsRefunded()
    {
        $this->update([
            'status' => 'refunded',
            'refunded_at' => now()
        ]);

        // Mettre à jour les statistiques du teacher
        $this->teacher->decrement('total_earnings', $this->teacher_amount);
        $this->teacher->decrement('total_commission_paid', $this->commission_amount);
    }
}
