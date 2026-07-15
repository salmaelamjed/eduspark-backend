<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'teacher_id',
        'course_id',
        'amount',
        'rate',
        'stripe_transfer_id',
        'stripe_fee_id',
        'status',
        'paid_at'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'rate' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function purchase()
    {
        return $this->belongsTo(CoursePurchase::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function markAsPaid($stripeTransferId = null)
    {
        $this->update([
            'status' => 'paid',
            'stripe_transfer_id' => $stripeTransferId,
            'paid_at' => now()
        ]);
    }
}
