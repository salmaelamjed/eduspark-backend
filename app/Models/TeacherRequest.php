<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'domain_id',
        'linkedin_url',
        'project_url',
        'motivation',
        'status',
        'admin_comment',
    ];

    protected $casts = [
        'status'      => 'string',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Relations
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Scope : demandes en attente
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope : demandes approuvées
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope : demandes rejetées
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Accesseur : statut formaté (pour affichage frontend)
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending'   => 'En attente',
            'approved'  => 'Approuvée',
            'rejected'  => 'Rejetée',
            default     => ucfirst($this->status),
        };
    }

    /**
     * Accesseur : couleur de badge pour le statut (utilisé dans frontend)
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending'   => 'warning',
            'approved'  => 'success',
            'rejected'  => 'danger',
            default     => 'secondary',
        };
    }

    /**
     * Méthode d'aide : est-ce que la demande est en attente ?
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Méthode d'aide : est-ce que la demande a été approuvée ?
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
