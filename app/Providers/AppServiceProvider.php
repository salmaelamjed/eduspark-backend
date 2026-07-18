<?php

namespace App\Providers;

use App\Models\ChatRoom;
use App\Policies\ChatRoomPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use App\Services\AI\GroqAIService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {
        $this->app->singleton(GroqAIService::class, fn () => GroqAIService::fromConfig());

    }

    public function boot(): void
    {
        Gate::policy(ChatRoom::class, ChatRoomPolicy::class);
    }
}