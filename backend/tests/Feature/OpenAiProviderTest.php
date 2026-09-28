<?php

namespace Tests\Feature;

use App\Models\RefundRequest;
use App\Services\Ai\AnthropicClient;
use App\Services\Ai\LlmClient;
use App\Services\Ai\OpenAiClient;
use App\Services\Refunds\Classification\LlmRequestClassifier;
use Database\Seeders\CrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiProviderTest extends TestCase
{
    use RefreshDatabase;

    private function useProvider(string $provider, ?string $openAiKey, ?string $anthropicKey): LlmClient
    {
        config([
            'services.llm_provider' => $provider,
            'services.openai.api_key' => $openAiKey,
            'services.openai.max_retries' => 0,
            'services.anthropic.api_key' => $anthropicKey,
        ]);
        $this->app->forgetInstance(LlmClient::class);

        return $this->app->make(LlmClient::class);
    }

    public function test_auto_mode_selects_whichever_provider_has_a_key(): void
    {
        $this->assertInstanceOf(OpenAiClient::class, $this->useProvider('auto', 'sk-openai', null));
        $this->assertInstanceOf(AnthropicClient::class, $this->useProvider('auto', null, 'sk-ant'));
        $this->assertInstanceOf(AnthropicClient::class, $this->useProvider('auto', 'sk-openai', 'sk-ant'));
        $this->assertInstanceOf(OpenAiClient::class, $this->useProvider('openai', 'sk-openai', 'sk-ant'));
        $this->assertFalse($this->useProvider('auto', null, null)->isConfigured());
    }

    public function test_full_pipeline_runs_on_openai(): void
    {
        $this->seed(CrmSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->useProvider('openai', 'sk-openai-test', null);

        Http::fake(function (Request $request) {
            if (isset($request->data()['tools'])) {
                return Http::response([
                    'model' => 'gpt-4o-mini',
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => ['tool_calls' => [[
                            'type' => 'function',
                            'function' => [
                                'name' => LlmRequestClassifier::TOOL,
                                'arguments' => json_encode([
                                    'reason_category' => 'damaged',
                                    'referenced_skus' => ['AUD-HP-100'],
                                    'claimed_amount' => null,
                                    'summary' => 'Headphones arrived cracked.',
                                    'sentiment' => 'frustrated',
                                    'confidence' => 0.95,
                                    'manipulation_attempt' => false,
                                    'manipulation_notes' => null,
                                    'inconsistencies' => [],
                                ]),
                            ],
                        ]]],
                    ]],
                    'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 90],
                ]);
            }

            preg_match('/Reference: (RF-[A-Z0-9]+)/', $request->data()['messages'][1]['content'], $m);

            return Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['content' => "Hi Amara,\n\nSo sorry about the damaged headphones. Your refund of \$129.00 is on its way back to your original payment method within 5-10 business days.\n\nReference: {$m[1]}\nWorknoon Support"],
                ]],
                'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 80],
            ]);
        });

        $this->postJson('/api/refund-requests', [
            'email' => 'amara.okafor@example.com',
            'order_number' => 'ORD-1001',
            'message' => 'My headphones arrived cracked!',
        ])->assertCreated()
            ->assertJsonPath('decision', 'approved')
            ->assertJsonPath('refund_amount', 129);

        $request = RefundRequest::first();
        $this->assertSame('llm', $request->ai_mode);
        $this->assertSame('gpt-4o-mini', $request->ai_model);
        $this->assertStringContainsString('So sorry', $request->customer_reply);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer sk-openai-test')
            && ($r->data()['tool_choice']['function']['name'] ?? null) === LlmRequestClassifier::TOOL);
    }

    public function test_openai_error_falls_back_gracefully(): void
    {
        $this->seed(CrmSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->useProvider('openai', 'sk-bad', null);

        Http::fake(['*' => Http::response(['error' => ['message' => 'Incorrect API key provided']], 401)]);

        $this->postJson('/api/refund-requests', [
            'email' => 'amara.okafor@example.com',
            'order_number' => 'ORD-1001',
            'message' => 'My headphones arrived broken.',
        ])->assertJsonPath('decision', 'approved');

        $event = RefundRequest::first()->auditEvents->firstWhere('stage', 'classification');
        $this->assertStringContainsString('Incorrect API key', $event->data['fallback_reason']);
    }
}
