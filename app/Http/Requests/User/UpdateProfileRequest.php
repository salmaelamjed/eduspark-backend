<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorisation fine déléguée à la Policy dans le contrôleur ($this->authorize).
        // Ici on s'assure juste qu'un user authentifié est présent.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255', 'regex:/^[\pL\s\-\'\.]+$/u'],
            'headline' => ['nullable', 'string', 'max:150'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'country' => ['nullable', 'string', 'size:2', 'alpha', Rule::in($this->validCountryCodes())],
            'date_of_birth' => ['nullable', 'date', 'before:-13 years', 'after:-120 years'],
            'expertise_level' => [
                'nullable',
                'string',
                Rule::in(['beginner', 'intermediate', 'advanced', 'expert']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Le nom contient des caractères non autorisés.',
            'country.in' => 'Code pays invalide.',
            'date_of_birth.before' => 'Vous devez avoir au moins 13 ans.',
            'date_of_birth.after' => 'Date de naissance invalide.',
            'expertise_level.in' => 'Le niveau d\'expertise sélectionné est invalide.',
        ];
    }

    /**
     * Liste blanche de codes pays ISO 3166-1 alpha-2 (extrait — à compléter
     * ou externaliser dans un fichier de config selon vos besoins).
     */
    private function validCountryCodes(): array
    {
        return array_map('strtoupper', config('countries.iso_codes', [
            'MA', 'FR', 'US', 'GB', 'DE', 'ES', 'IT', 'CA', 'BE', 'CH', 'NL', 'TN', 'DZ', 'EG',
        ]));
    }
}