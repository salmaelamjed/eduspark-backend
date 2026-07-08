<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailVerificationCode extends Model
{
    protected $fillable = ['user_id', 'code', 'expires_at', 'used_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function createForUser($user): self
    {
        // Invalider les anciens codes non utilisés
        self::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        return self::create([
            'user_id'    => $user->id,
            'code'       => $code,
            'expires_at' => now()->addMinutes(15), // 15 min d'expiration
        ]);
    }

    public function isValid(): bool
    {
        return is_null($this->used_at) && now()->lt($this->expires_at);
    }

    public function markAsUsed(): void
    {
        $this->update(['used_at' => now()]);
    }
}