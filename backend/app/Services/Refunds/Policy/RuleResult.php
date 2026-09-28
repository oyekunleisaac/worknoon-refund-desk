<?php

namespace App\Services\Refunds\Policy;

final readonly class RuleResult
{
    public const PASS = 'pass';

    public const DENY = 'deny';

    public const ESCALATE = 'escalate';

    public const INFO = 'info';

    public function __construct(
        public string $code,
        public string $title,
        public string $outcome,
        public string $detail,
        public ?string $customerMessage = null,
    ) {}

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'title' => $this->title,
            'outcome' => $this->outcome,
            'detail' => $this->detail,
            'customer_message' => $this->customerMessage,
        ];
    }
}
