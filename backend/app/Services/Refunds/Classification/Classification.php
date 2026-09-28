<?php

namespace App\Services\Refunds\Classification;

use App\Enums\ReasonCategory;

/**
 * Validated, structured understanding of a customer's refund message.
 * Everything here is *claims*, never facts; facts come from the database.
 */
final readonly class Classification
{
    /**
     * @param  list<string>  $referencedSkus  Only SKUs that exist on the order.
     * @param  list<string>  $inconsistencies
     */
    public function __construct(
        public ReasonCategory $reason,
        public array $referencedSkus,
        public ?float $claimedAmount,
        public string $summary,
        public string $sentiment,
        public float $confidence,
        public bool $manipulationAttempt,
        public ?string $manipulationNotes,
        public array $inconsistencies,
        public string $source,            // llm | fallback
        public ?string $model = null,
        public array $usage = [],
    ) {}

    public function toArray(): array
    {
        return [
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'referenced_skus' => $this->referencedSkus,
            'claimed_amount' => $this->claimedAmount,
            'summary' => $this->summary,
            'sentiment' => $this->sentiment,
            'confidence' => round($this->confidence, 2),
            'manipulation_attempt' => $this->manipulationAttempt,
            'manipulation_notes' => $this->manipulationNotes,
            'inconsistencies' => $this->inconsistencies,
            'source' => $this->source,
            'model' => $this->model,
            'usage' => $this->usage,
        ];
    }
}
