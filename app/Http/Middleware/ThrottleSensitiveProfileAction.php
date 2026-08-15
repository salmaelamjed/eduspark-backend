<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limite les tentatives sur les actions sensibles (mot de passe, email, avatar)
 * indépendamment du throttle global de l'API, avec une clé par utilisateur + action.
 */
class ThrottleSensitiveProfileAction
{
    public function handle(Request $request, Closure $next, string $action, int $maxAttempts = 5, int $decayMinutes = 15): Response
    {
        $key = 'profile-action:' . $action . ':' . $request->user()->id;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => "Trop de tentatives. Réessayez dans {$seconds} secondes.",
            ], 429);
        }

        RateLimiter::hit($key, $decayMinutes * 60);

        $response = $next($request);

        // Si l'action a réussi (2xx), on efface le compteur pour ne pas pénaliser l'usage légitime
        if ($response->getStatusCode() < 300) {
            RateLimiter::clear($key);
        }

        return $response;
    }
}
