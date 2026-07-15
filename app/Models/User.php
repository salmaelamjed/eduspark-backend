<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;
    use HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
   protected $fillable = [
    'name',
    'email',
    'password',
    'role',
    'is_active',
    'profile_picture',
    'bio',
    'headline',
    'country',
    'social_links',
    'expertise_level',
    'date_of_birth',
    'stripe_account_id',
    'stripe_onboarding_completed',
    'stripe_account_created_at',
    'stripe_account_updated_at',
    'total_earnings',
    'total_commission_paid',
];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'social_links' => 'array',
            'date_of_birth' => 'date',
            'stripe_onboarding_completed' => 'boolean',
            'stripe_account_created_at' => 'datetime',
            'stripe_account_updated_at' => 'datetime',
            'total_earnings' => 'decimal:2',
            'total_commission_paid' => 'decimal:2',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    public function teacherRequest()
    {
        return $this->hasOne(TeacherRequest::class);
    }
}
