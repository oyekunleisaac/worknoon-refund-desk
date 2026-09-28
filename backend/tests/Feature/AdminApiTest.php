<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\RefundRequest;
use Database\Seeders\CrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private array $auth = ['Authorization' => 'Bearer test-admin-token'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CrmSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function escalatedLaptop(): RefundRequest
    {
        $this->postJson('/api/refund-requests', [
            'email' => 'david.mensah@example.com',
            'order_number' => 'ORD-1004',
            'message' => 'The laptop screen arrived cracked.',
        ])->assertJsonPath('decision', 'escalated');

        return RefundRequest::latest('id')->first();
    }

    public function test_admin_endpoints_require_the_token(): void
    {
        $this->getJson('/api/admin/refund-requests')->assertUnauthorized();
        $this->getJson('/api/admin/refund-requests', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->getJson('/api/admin/refund-requests', $this->auth)->assertOk();
    }

    public function test_detail_includes_reasoning_and_audit_trail(): void
    {
        $request = $this->escalatedLaptop();

        $this->getJson("/api/admin/refund-requests/{$request->id}", $this->auth)
            ->assertOk()
            ->assertJsonPath('status', 'pending_review')
            ->assertJsonPath('refund_amount', 1299)
            ->assertJsonPath('policy_evaluation.decision', 'escalated')
            ->assertJsonFragment(['code' => 'AMOUNT_HUMAN_REVIEW', 'outcome' => 'escalate'])
            ->assertJsonStructure(['classification' => ['reason', 'confidence'], 'audit_events' => [['stage', 'actor', 'summary']]]);
    }

    public function test_filters_and_stats(): void
    {
        $this->escalatedLaptop();
        $this->postJson('/api/refund-requests', [
            'email' => 'oliver.smith@example.com', 'order_number' => 'ORD-1015',
            'message' => 'I changed my mind, I no longer need the book.',
        ]);

        $this->getJson('/api/admin/refund-requests?decision=escalated', $this->auth)->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/refund-requests?search=oliver', $this->auth)->assertJsonCount(1, 'data');

        $this->getJson('/api/admin/stats', $this->auth)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('approved', 1)
            ->assertJsonPath('escalated', 1)
            ->assertJsonPath('pending_review', 1)
            ->assertJsonPath('refunded_amount', 35);
    }

    public function test_reviewer_can_approve_an_escalated_request(): void
    {
        $request = $this->escalatedLaptop();

        $this->postJson("/api/admin/refund-requests/{$request->id}/review", [
            'action' => 'approve', 'note' => 'Photos confirm cracked screen.', 'reviewer' => 'Sam (Support)',
        ], $this->auth)
            ->assertOk()
            ->assertJsonPath('decision', 'approved')
            ->assertJsonPath('status', 'reviewed')
            ->assertJsonPath('reviewed_by', 'Sam (Support)');

        $this->assertNotNull(OrderItem::where('sku', 'CMP-LP-014')->value('refunded_at'));
        $this->assertSame('human', $request->auditEvents->last()->actor);

        // Customer can now see the resolution.
        $this->getJson("/api/refund-requests/{$request->reference}?email=david.mensah@example.com")
            ->assertJsonPath('decision', 'approved')
            ->assertJsonPath('refund_amount', 1299);
    }

    public function test_reviewer_can_deny_and_cannot_review_twice(): void
    {
        $request = $this->escalatedLaptop();
        $payload = ['action' => 'deny', 'note' => 'Damage caused by customer.', 'reviewer' => 'Sam'];

        $this->postJson("/api/admin/refund-requests/{$request->id}/review", $payload, $this->auth)->assertJsonPath('decision', 'denied');
        $this->postJson("/api/admin/refund-requests/{$request->id}/review", $payload, $this->auth)->assertUnprocessable();

        $this->assertNull(OrderItem::where('sku', 'CMP-LP-014')->value('refunded_at'));
    }

    public function test_demo_reset_restores_data(): void
    {
        $this->postJson('/api/refund-requests', [
            'email' => 'amara.okafor@example.com', 'order_number' => 'ORD-1001',
            'message' => 'My headphones arrived broken.',
        ]);

        $this->postJson('/api/admin/demo/reset', [], $this->auth)->assertOk();

        $this->assertSame(0, RefundRequest::count());
        $this->assertNull(OrderItem::where('sku', 'AUD-HP-100')->value('refunded_at'));
    }
}
