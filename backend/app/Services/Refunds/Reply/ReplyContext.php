<?php

namespace App\Services\Refunds\Reply;

use App\Enums\Decision;

final readonly class ReplyContext
{
    /**
     * @param  list<string>  $refundedItems
     * @param  list<string>  $reasons  Customer-safe explanations from the policy engine.
     */
    public function __construct(
        public string $firstName,
        public string $reference,
        public string $orderNumber,
        public Decision $decision,
        public float $amount,
        public array $refundedItems,
        public array $reasons,
        public string $requestSummary,
        public string $sentiment,
    ) {}

    public function formattedAmount(): string
    {
        return '$'.number_format($this->amount, 2);
    }
}
