<?php

namespace App\Services\Refunds;

use App\Enums\Decision;
use App\Enums\RequestStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RefundRequest;
use App\Services\Refunds\Classification\RequestClassifier;
use App\Services\Refunds\Policy\RefundPolicyEngine;
use App\Services\Refunds\Reply\ReplyComposer;
use App\Services\Refunds\Reply\ReplyContext;
use App\Services\Refunds\Security\InputSanitizer;
use App\Services\Refunds\Security\PromptInjectionDetector;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Orchestrates one refund request end to end:
 *
 *   receive → verify identity → injection scan → AI classification
 *     → deterministic policy → execute refund → AI reply (+ guard) → persist
 *
 * Every stage writes an audit event so support staff can see exactly why a
 * decision was made and which parts were AI vs. rules.
 */
class RefundRequestProcessor
{
    public function __construct(
        private readonly InputSanitizer $sanitizer,
        private readonly PromptInjectionDetector $detector,
        private readonly RequestClassifier $classifier,
        private readonly RefundPolicyEngine $policy,
        private readonly RefundExecutor $executor,
        private readonly ReplyComposer $composer,
    ) {}

    public function process(string $email, string $orderNumber, string $message, ?string $ip = null): RefundRequest
    {
        $email = mb_strtolower(trim($email));
        $orderNumber = Order::normalizeNumber($orderNumber);
        $message = $this->sanitizer->clean($message);

        $request = RefundRequest::create([
            'submitted_email' => $email,
            'submitted_order_number' => $orderNumber,
            'message' => $message,
            'status' => RequestStatus::Processing,
            'ip_address' => $ip,
        ]);
        $request->audit('received', 'system', 'Refund request received via web chat.', ['characters' => mb_strlen($message)]);

        $order = $this->verify($email, $orderNumber);

        if (! $order) {
            return $this->rejectUnverified($request);
        }

        $request->update(['customer_id' => $order->customer_id, 'order_id' => $order->id]);
        $request->audit('verification', 'system', 'Customer identity verified against order record.', [
            'customer' => $order->customer->customer_number,
            'order' => $order->order_number,
        ]);

        // One pipeline per order at a time: prevents double refunds from
        // double-clicks or parallel submissions.
        $lock = Cache::lock("refund-order:{$order->id}", 120);

        if (! $lock->get()) {
            return $this->fail($request, 'Another request for this order is being processed.', escalate: false);
        }

        try {
            return $this->run($request, $order, $message);
        } catch (Throwable $e) {
            report($e);

            return $this->fail($request, $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    private function run(RefundRequest $request, Order $order, string $message): RefundRequest
    {
        $order->load('items', 'customer');

        // 1. Heuristic injection scan (pre-LLM).
        $scan = $this->detector->scan($message);
        $request->audit('injection_scan', 'system',
            $scan->suspicious ? 'Manipulation patterns detected: '.implode(', ', $scan->signals).'.' : 'No manipulation patterns detected.',
            $scan->toArray());

        // 2. AI classification (claims only).
        $started = hrtime(true);
        $classification = $this->classifier->classify($message, $order);
        $request->audit('classification', $classification->source === 'llm' ? 'ai' : 'system',
            sprintf('Classified as "%s" (confidence %.2f) by %s.', $classification->reason->label(), $classification->confidence,
                $classification->source === 'llm' ? $classification->model : 'keyword fallback'),
            array_filter([...$classification->toArray(), 'fallback_reason' => $this->classifier->lastError]),
            (int) ((hrtime(true) - $started) / 1_000_000));

        // 3. Deterministic policy decision.
        $outcome = $this->policy->evaluate($order, $classification, $scan);
        $request->audit('policy', 'policy_engine',
            sprintf('Decision: %s%s.', $outcome->decision->label(), $outcome->decision === Decision::Denied ? '' : ' ($'.number_format($outcome->refundAmount, 2).')'),
            $outcome->toArray());

        $itemIds = $outcome->eligibleItems->pluck('id')->all();

        // 4. Execute approved refunds before telling the customer anything.
        if ($outcome->decision === Decision::Approved) {
            $this->executor->execute($order, $itemIds);
            $request->audit('refund', 'system', 'Refund of $'.number_format($outcome->refundAmount, 2).' issued to original payment method.', [
                'item_ids' => $itemIds,
            ]);
        }

        // 5. Customer reply (AI drafted, guarded).
        $reply = $this->composer->compose(new ReplyContext(
            firstName: $order->customer->firstName(),
            reference: $request->reference,
            orderNumber: $order->order_number,
            decision: $outcome->decision,
            amount: $outcome->refundAmount,
            refundedItems: $outcome->eligibleItems->map(fn (OrderItem $i) => $i->name)->all(),
            reasons: $outcome->customerReasons(),
            requestSummary: $classification->summary,
            sentiment: $classification->sentiment,
        ));
        $request->audit('reply', $reply['source'] === 'llm' ? 'ai' : 'system',
            $reply['source'] === 'llm'
                ? 'Customer reply drafted by AI and passed output guard.'
                : 'Customer reply generated from template'.($reply['violations'] ? ' (AI draft rejected by output guard).' : '.'),
            array_filter(['violations' => $reply['violations'], 'error' => $reply['error'], 'usage' => $reply['usage']]),
            $reply['usage']['duration_ms'] ?? null);

        $request->update([
            'decision' => $outcome->decision,
            'status' => $outcome->decision === Decision::Escalated ? RequestStatus::PendingReview : RequestStatus::Resolved,
            'reason_category' => $classification->reason->value,
            'refund_amount' => $outcome->refundAmount,
            'item_ids' => $itemIds,
            'customer_reply' => $reply['reply'],
            'classification' => $classification->toArray(),
            'policy_evaluation' => $outcome->toArray(),
            'risk_flags' => $outcome->riskFlags ?: null,
            'ai_mode' => $classification->source,
            'ai_model' => $classification->model,
        ]);

        return $request->fresh();
    }

    private function verify(string $email, string $orderNumber): ?Order
    {
        $customer = Customer::where('email', $email)->first();

        return $customer?->orders()->with('customer')->where('order_number', $orderNumber)->first();
    }

    /**
     * Identical response whether the order does not exist or belongs to
     * someone else, to avoid leaking which order numbers are valid.
     */
    private function rejectUnverified(RefundRequest $request): RefundRequest
    {
        $request->audit('verification', 'system', 'Email and order number do not match any order.');
        $request->update([
            'decision' => Decision::Denied,
            'status' => RequestStatus::Resolved,
            'risk_flags' => ['verification_failed'],
            'customer_reply' => "We couldn't find an order matching that email address and order number. Please double-check both (the order number looks like ORD-1001) and try again.\n\nReference: {$request->reference}\n\nWorknoon Support",
        ]);

        return $request->fresh();
    }

    private function fail(RefundRequest $request, string $error, bool $escalate = true): RefundRequest
    {
        $request->audit('error', 'system', 'Processing failed: '.$error);
        $request->update([
            'decision' => $escalate ? Decision::Escalated : Decision::Denied,
            'status' => $escalate ? RequestStatus::Failed : RequestStatus::Resolved,
            'risk_flags' => $escalate ? ['processing_error'] : ['concurrent_request'],
            'customer_reply' => $escalate
                ? "Thanks for your patience. We couldn't finish processing your request automatically, so it has been passed to a support specialist who will update you within 1-2 business days.\n\nReference: {$request->reference}\n\nWorknoon Support"
                : "We're already processing a request for this order. Please wait a moment and check its status before submitting again.\n\nReference: {$request->reference}\n\nWorknoon Support",
        ]);

        return $request->fresh();
    }
}
