<?php

namespace App\Services\Refunds\Policy;

use App\Enums\Decision;
use App\Models\OrderItem;
use Illuminate\Support\Collection;

final readonly class PolicyOutcome
{
    /**
     * @param  Collection<int, OrderItem>  $eligibleItems
     * @param  Collection<int, OrderItem>  $excludedItems
     * @param  list<RuleResult>  $rules
     * @param  list<string>  $riskFlags
     */
    public function __construct(
        public Decision $decision,
        public float $refundAmount,
        public Collection $eligibleItems,
        public Collection $excludedItems,
        public array $rules,
        public array $riskFlags,
    ) {}

    /** Customer-safe explanations from the rules that drove the decision. */
    public function customerReasons(): array
    {
        // Never tell a suspicious requester which checks they tripped: the
        // generic "a specialist will review it" wording is all they get.
        if ($this->decision === Decision::Escalated && $this->hasSecurityEscalation()) {
            return [];
        }

        $driving = match ($this->decision) {
            Decision::Denied => [RuleResult::DENY],
            Decision::Escalated => [RuleResult::ESCALATE],
            Decision::Approved => [RuleResult::DENY, RuleResult::INFO],   // partial refunds explain exclusions
        };

        return collect($this->rules)
            ->filter(fn (RuleResult $r) => in_array($r->outcome, $driving, true) && $r->customerMessage)
            ->map(fn (RuleResult $r) => $r->customerMessage)
            ->unique()
            ->values()
            ->all();
    }

    public function hasSecurityEscalation(): bool
    {
        return collect($this->rules)->contains(fn (RuleResult $r) => $r->outcome === RuleResult::ESCALATE
            && (str_starts_with($r->code, 'SEC_') || str_starts_with($r->code, 'RISK_')));
    }

    public function isPartial(): bool
    {
        return $this->decision === Decision::Approved && $this->excludedItems->isNotEmpty();
    }

    public function toArray(): array
    {
        return [
            'decision' => $this->decision->value,
            'refund_amount' => $this->refundAmount,
            'partial' => $this->isPartial(),
            'eligible_items' => $this->eligibleItems->map(fn (OrderItem $i) => [
                'id' => $i->id, 'sku' => $i->sku, 'name' => $i->name, 'amount' => $i->lineTotal(),
            ])->values()->all(),
            'excluded_items' => $this->excludedItems->map(fn (OrderItem $i) => [
                'id' => $i->id, 'sku' => $i->sku, 'name' => $i->name, 'amount' => $i->lineTotal(),
            ])->values()->all(),
            'rules' => array_map(fn (RuleResult $r) => $r->toArray(), $this->rules),
            'risk_flags' => $this->riskFlags,
        ];
    }
}
