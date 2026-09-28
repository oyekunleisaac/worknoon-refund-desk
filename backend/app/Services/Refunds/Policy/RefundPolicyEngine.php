<?php

namespace App\Services\Refunds\Policy;

use App\Enums\Decision;
use App\Enums\OrderStatus;
use App\Enums\ReasonCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RefundRequest;
use App\Services\Refunds\Classification\Classification;
use App\Services\Refunds\Security\InjectionScan;
use Carbon\CarbonInterface;

/**
 * Deterministic, auditable refund policy.
 *
 * This is the only component that decides an outcome. It consumes verified
 * order facts from the database plus the (validated) AI classification of
 * what the customer is claiming, and records every rule it checked.
 *
 * Precedence:
 *   1. Security escalations (manipulation, flagged account) always win.
 *      A suspicious request is never auto-denied or auto-approved.
 *   2. Hard denials (ineligible order / window / final sale...).
 *   3. Other escalations (amount threshold, conflicts, low confidence, risk).
 *   4. Otherwise approve the eligible items (possibly a partial refund).
 */
class RefundPolicyEngine
{
    /** @var list<RuleResult> */
    private array $rules = [];

    public function __construct(private readonly array $config) {}

    public function evaluate(
        Order $order,
        Classification $classification,
        InjectionScan $scan,
        ?CarbonInterface $now = null,
    ): PolicyOutcome {
        $this->rules = [];
        $now ??= now();
        $order->loadMissing('items', 'customer');
        $customer = $order->customer;
        $reason = $classification->reason;
        $riskFlags = [];

        // -----------------------------------------------------------------
        // 1. Security & risk
        // -----------------------------------------------------------------
        if ($scan->suspicious || $classification->manipulationAttempt) {
            $riskFlags[] = 'prompt_injection';
            $this->escalate('SEC_MANIPULATION', 'Manipulation / prompt-injection check',
                'Message contains attempts to instruct or manipulate the system ('
                .implode(', ', array_filter([implode(', ', $scan->signals), $classification->manipulationNotes])).').',
                'Your request needs a quick manual review by our team.');
        } else {
            $this->pass('SEC_MANIPULATION', 'Manipulation / prompt-injection check', 'No manipulation signals detected.');
        }

        if ($customer->account_flagged) {
            $riskFlags[] = 'account_flagged';
            $this->escalate('RISK_ACCOUNT_FLAGGED', 'Account standing',
                'Customer account is flagged: '.($customer->flag_reason ?? 'no reason recorded').'.',
                'Your request needs a quick manual review by our team.');
        } else {
            $this->pass('RISK_ACCOUNT_FLAGGED', 'Account standing', 'Account in good standing.');
        }

        $lookback = $this->config['risk']['lookback_days'];
        $maxRefunds = $this->config['risk']['max_refunds_in_lookback'];
        $recentRefunds = $customer->refundedOrdersSince($now->copy()->subDays($lookback));
        if ($recentRefunds >= $maxRefunds) {
            $riskFlags[] = 'high_refund_frequency';
            $this->escalate('RISK_REFUND_FREQUENCY', 'Refund frequency',
                "{$recentRefunds} orders refunded in the last {$lookback} days (limit {$maxRefunds}).",
                'Your request needs a quick manual review by our team.');
        } else {
            $this->pass('RISK_REFUND_FREQUENCY', 'Refund frequency', "{$recentRefunds} refunded orders in the last {$lookback} days.");
        }

        // -----------------------------------------------------------------
        // 2. Order state
        // -----------------------------------------------------------------
        $openRequest = RefundRequest::query()
            ->where('order_id', $order->id)
            ->where('status', 'pending_review')
            ->first();

        if ($openRequest) {
            $this->deny('ORDER_DUPLICATE_REQUEST', 'Duplicate request',
                "Request {$openRequest->reference} for this order is already awaiting review.",
                "You already have a refund request ({$openRequest->reference}) for this order that our team is reviewing. We'll update you there.");
        }

        if ($order->status === OrderStatus::Cancelled) {
            $this->deny('ORDER_CANCELLED', 'Order status',
                'Order was cancelled before fulfilment; no charge was captured.',
                'This order was cancelled before it shipped, so no payment was taken and there is nothing to refund.');
        }

        // Candidate items: the ones the customer referenced, else the whole order.
        $candidates = $classification->referencedSkus !== []
            ? $order->items->filter(fn (OrderItem $i) => in_array(strtoupper($i->sku), $classification->referencedSkus, true))
            : $order->items;

        $alreadyRefunded = $candidates->filter->isRefunded();
        $candidates = $candidates->reject->isRefunded();

        if ($candidates->isEmpty() && $order->status !== OrderStatus::Cancelled) {
            $this->deny('ORDER_ALREADY_REFUNDED', 'Previously refunded',
                'All requested items have already been refunded.',
                'The item(s) you asked about have already been refunded.');
        } elseif ($alreadyRefunded->isNotEmpty()) {
            $this->info('ORDER_ALREADY_REFUNDED', 'Previously refunded',
                'Skipping already-refunded items: '.$alreadyRefunded->pluck('name')->implode(', ').'.',
                'Some items were already refunded earlier and are not included again.');
        }

        // -----------------------------------------------------------------
        // 3. Delivery & refund window
        // -----------------------------------------------------------------
        $lostDays = $this->config['lost_in_transit_days'];
        $windowDays = $this->config['refund_window_days'];

        if ($reason === ReasonCategory::NotReceived) {
            if ($order->status === OrderStatus::Delivered || $order->carrier_status === 'delivered') {
                $riskFlags[] = 'delivery_conflict';
                $this->escalate('DELIVERY_CONFLICT', 'Delivery verification',
                    'Customer reports non-delivery but carrier confirmed delivery on '.optional($order->delivered_at)->toDateString().'.',
                    'Our carrier shows this order as delivered, so a specialist will open a trace with the carrier and follow up with you.');
            } elseif ($order->status === OrderStatus::Shipped) {
                $daysInTransit = (int) $order->shipped_at->diffInDays($now);
                if ($daysInTransit <= $lostDays) {
                    $this->deny('DELIVERY_IN_TRANSIT', 'Delivery verification',
                        "Package in transit for {$daysInTransit} days (lost after {$lostDays}).",
                        "Your package is still in transit (shipped {$daysInTransit} days ago). If it hasn't arrived {$lostDays} days after shipping, contact us again and it can be refunded as lost.");
                } else {
                    $this->pass('DELIVERY_LOST', 'Delivery verification', "In transit for {$daysInTransit} days; treated as lost.");
                }
            } elseif ($order->status === OrderStatus::Processing) {
                $this->escalate('DELIVERY_NOT_SHIPPED', 'Delivery verification',
                    'Order not yet shipped; customer may want a cancellation.',
                    'Your order has not shipped yet; a specialist will help you cancel it.');
            }
        } elseif (! in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)) {
            if ($order->delivered_at === null) {
                $this->deny('WINDOW_NOT_DELIVERED', 'Refund window',
                    'Order has not been delivered yet; refund eligibility starts at delivery.',
                    "Your order hasn't been delivered yet. Refund requests can be made once it arrives.");
            } else {
                $age = (int) $order->delivered_at->diffInDays($now);
                if ($age > $windowDays) {
                    $this->deny('WINDOW_EXPIRED', 'Refund window',
                        "Delivered {$age} days ago; window is {$windowDays} days.",
                        "Refunds must be requested within {$windowDays} days of delivery, and this order was delivered {$age} days ago.");
                } else {
                    $this->pass('WINDOW_EXPIRED', 'Refund window', "Delivered {$age} days ago (within {$windowDays}).");
                }
            }
        }

        // -----------------------------------------------------------------
        // 4. Item eligibility (final sale)
        // -----------------------------------------------------------------
        $finalSale = $candidates->filter->final_sale;
        $eligible = $candidates->reject->final_sale;
        $excluded = collect();

        if ($finalSale->isNotEmpty()) {
            $names = $finalSale->pluck('name')->implode(', ');
            if ($reason->isMerchantFault()) {
                $this->escalate('ITEM_FINAL_SALE_DEFECT', 'Final sale items',
                    "Final-sale item(s) reported {$reason->value}: {$names}. Exception requires human review.",
                    'Final-sale items are normally non-refundable, but because you reported a problem with the item, a specialist will review it.');
                $eligible = $candidates;
            } elseif ($eligible->isEmpty()) {
                $this->deny('ITEM_FINAL_SALE', 'Final sale items',
                    "All requested items are final sale: {$names}.",
                    "Final-sale items ({$names}) are not eligible for a refund.");
            } else {
                $excluded = $finalSale;
                $this->info('ITEM_FINAL_SALE', 'Final sale items',
                    "Excluded final-sale item(s) from refund: {$names}.",
                    "Final-sale items ({$names}) are not eligible and were not included.");
            }
        } else {
            $this->pass('ITEM_FINAL_SALE', 'Final sale items', 'No final-sale items in the request.');
        }

        // -----------------------------------------------------------------
        // 5. Reason, confidence and consistency
        // -----------------------------------------------------------------
        if ($reason === ReasonCategory::Other) {
            $this->escalate('REASON_UNCLEAR', 'Refund reason',
                'Reason could not be determined from the message.',
                "We couldn't tell exactly what went wrong from your message, so a specialist will review it.");
        } else {
            $this->pass('REASON_UNCLEAR', 'Refund reason', "Classified as {$reason->label()}.");
        }

        $minConfidence = $this->config['min_ai_confidence'];
        if ($classification->confidence < $minConfidence && $reason !== ReasonCategory::Other) {
            $this->escalate('REASON_LOW_CONFIDENCE', 'Classification confidence',
                sprintf('Classifier confidence %.2f is below %.2f.', $classification->confidence, $minConfidence),
                'A specialist will double-check the details of your request.');
        }

        if ($classification->inconsistencies !== []) {
            $riskFlags[] = 'claim_conflict';
            $this->escalate('CLAIM_INCONSISTENT', 'Claim consistency',
                'Claims conflict with the order record: '.implode(' ', $classification->inconsistencies),
                'Some details in your message differ from our order records, so a specialist will review them with you.');
        }

        // -----------------------------------------------------------------
        // 6. Amount
        // -----------------------------------------------------------------
        $amount = round($eligible->sum(fn (OrderItem $i) => $i->lineTotal()), 2);
        $threshold = $this->config['human_review_threshold'];

        if ($amount > $threshold) {
            $this->escalate('AMOUNT_HUMAN_REVIEW', 'Refund amount',
                sprintf('Refund of $%s exceeds the $%s auto-approval limit.', number_format($amount, 2), number_format($threshold, 2)),
                sprintf('Refunds above $%s are reviewed by a member of our team.', number_format($threshold, 0)));
        } else {
            $this->pass('AMOUNT_HUMAN_REVIEW', 'Refund amount', sprintf('$%s is within the auto-approval limit.', number_format($amount, 2)));
        }

        $tolerance = $this->config['claimed_amount_tolerance'];
        if ($classification->claimedAmount !== null && $amount > 0 && $classification->claimedAmount > $amount * (1 + $tolerance)) {
            $riskFlags[] = 'amount_conflict';
            $this->escalate('CLAIM_AMOUNT_MISMATCH', 'Claimed amount',
                sprintf('Customer asked for $%s but eligible items total $%s.', number_format($classification->claimedAmount, 2), number_format($amount, 2)),
                'The amount you mentioned differs from what our records show, so a specialist will confirm it.');
        }

        $decision = $this->decide();

        return new PolicyOutcome(
            decision: $decision,
            // For escalations the amount is the *proposed* refund shown to the reviewer.
            refundAmount: $decision === Decision::Denied ? 0.0 : $amount,
            eligibleItems: $decision === Decision::Denied ? collect() : $eligible->values(),
            excludedItems: $excluded->values(),
            rules: $this->rules,
            riskFlags: array_values(array_unique($riskFlags)),
        );
    }

    private function decide(): Decision
    {
        $outcomes = collect($this->rules);
        $securityEscalation = $outcomes->contains(fn (RuleResult $r) => $r->outcome === RuleResult::ESCALATE
            && (str_starts_with($r->code, 'SEC_') || str_starts_with($r->code, 'RISK_')));

        return match (true) {
            $securityEscalation => Decision::Escalated,
            $outcomes->contains('outcome', RuleResult::DENY) => Decision::Denied,
            $outcomes->contains('outcome', RuleResult::ESCALATE) => Decision::Escalated,
            default => Decision::Approved,
        };
    }

    private function pass(string $code, string $title, string $detail): void
    {
        $this->rules[] = new RuleResult($code, $title, RuleResult::PASS, $detail);
    }

    private function info(string $code, string $title, string $detail, ?string $customer = null): void
    {
        $this->rules[] = new RuleResult($code, $title, RuleResult::INFO, $detail, $customer);
    }

    private function deny(string $code, string $title, string $detail, string $customer): void
    {
        $this->rules[] = new RuleResult($code, $title, RuleResult::DENY, $detail, $customer);
    }

    private function escalate(string $code, string $title, string $detail, string $customer): void
    {
        $this->rules[] = new RuleResult($code, $title, RuleResult::ESCALATE, $detail, $customer);
    }
}
