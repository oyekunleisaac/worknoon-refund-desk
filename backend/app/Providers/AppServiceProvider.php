<?php

namespace App\Providers;

use App\Services\Ai\AnthropicClient;
use App\Services\Ai\LlmClient;
use App\Services\Ai\OpenAiClient;
use App\Services\Refunds\Policy\RefundPolicyEngine;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmClient::class, fn () => $this->makeLlmClient());
        $this->app->bind(RefundPolicyEngine::class, fn () => new RefundPolicyEngine(config('refund')));
    }

    private function makeLlmClient(): LlmClient
    {
        $anthropic = new AnthropicClient(config('services.anthropic'));
        $openai = new OpenAiClient(config('services.openai'));

        return match (config('services.llm_provider')) {
            'openai' => $openai,
            'anthropic' => $anthropic,
            default => $anthropic->isConfigured() || ! $openai->isConfigured() ? $anthropic : $openai,
        };
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // Refund submissions call a paid LLM API and touch money: keep them tight.
        RateLimiter::for('refunds', fn (Request $request) => [
            Limit::perMinute(10)->by('refund:'.$request->ip()),
            Limit::perHour(60)->by('refund-h:'.$request->ip()),
        ]);

        // Order lookup is an enumeration target.
        RateLimiter::for('lookup', fn (Request $request) => Limit::perMinute(15)->by('lookup:'.$request->ip()));

        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(120)->by('admin:'.$request->ip()));
    }
}
