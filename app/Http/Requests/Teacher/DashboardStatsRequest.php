<?php

declare(strict_types=1);

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class DashboardStatsRequest extends FormRequest
{
    /**
     * Fenêtres autorisées, en jours. Empêche un client de demander un
     * intervalle arbitrairement grand (ex: period=999999) qui forcerait
     * un scan de plusieurs années de données à chaque requête.
     */
    private const ALLOWED_PERIODS = [7, 30, 90, 365];

    public function authorize(): bool
    {
        // Defense in depth : le middleware de route filtre déjà par rôle,
        // mais on le revérifie ici pour ne jamais dépendre d'un seul filet.
        return Auth::check() && Auth::user()->role === 'teacher';
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', 'integer', 'in:' . implode(',', self::ALLOWED_PERIODS)],
        ];
    }

    public function period(): int
    {
        return (int) ($this->validated('period') ?? 30);
    }
}
