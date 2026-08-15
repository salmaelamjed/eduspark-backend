<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TeacherAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required'
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->role !== 'teacher') {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Teacher role required.'
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is deactivated.'
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$user->stripe_onboarding_completed) {
            return response()->json([
                'success' => false,
                'message' => 'Complete your Stripe onboarding to access these features.'
            ], Response::HTTP_FORBIDDEN);
        }

        // Store teacher ID for later use
        $request->merge(['teacher_id' => $user->id]);

        return $next($request);
    }
}
