<?php

namespace App\Providers;

use App\Models\ChatRoom;
use App\Policies\ChatRoomPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Services\AI\GroqAIService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {
        $this->app->singleton(GroqAIService::class, fn () => GroqAIService::fromConfig());

    }

    public function boot(): void
    {
        Gate::policy(ChatRoom::class, ChatRoomPolicy::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        RateLimiter::for('sanctum', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });


    }
}
