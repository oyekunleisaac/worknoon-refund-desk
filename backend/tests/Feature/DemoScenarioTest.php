<?php

namespace Tests\Feature;

use App\Support\DemoCatalog;
use Database\Seeders\CrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every scenario advertised in the UI must produce its documented outcome,
 * even with no API key (deterministic keyword fallback).
 */
class DemoScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CrmSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public static function scenarios(): array
    {
        return collect(DemoCatalog::scenarios())
            ->mapWithKeys(fn ($s) => ["{$s['order_number']} {$s['title']}" => [$s]])
            ->all();
    }

    #[DataProvider('scenarios')]
    public function test_scenario_produces_expected_decision(array $scenario): void
    {
        $response = $this->postJson('/api/refund-requests', [
            'email' => $scenario['email'],
            'order_number' => $scenario['order_number'],
            'message' => $scenario['message'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('decision', $scenario['expected'])
            ->assertJsonStructure(['reference', 'decision', 'status', 'reply']);

        $this->assertStringContainsString($response->json('reference'), $response->json('reply'));
    }

    public function test_expected_amounts_for_approved_scenarios(): void
    {
        $cases = [
            ['amara.okafor@example.com', 'ORD-1001', 129.00],
            ['kofi.asante@example.com', 'ORD-1011', 70.00],     // final-sale jacket excluded
            ['mohammed.ali@example.com', 'ORD-1013', 25.00],    // only the cable
        ];

        foreach ($cases as [$email, $order, $amount]) {
            $message = collect(DemoCatalog::scenarios())->firstWhere('order_number', $order)['message'];

            $response = $this->postJson('/api/refund-requests', compact('email', 'message') + ['order_number' => $order])
                ->assertJsonPath('decision', 'approved');

            $this->assertEquals($amount, $response->json('refund_amount'), $order);
        }
    }
}
