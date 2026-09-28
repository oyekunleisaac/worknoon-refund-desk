<?php

namespace App\Services\Refunds\Security;

final readonly class InjectionScan
{
    /**
     * @param  list<string>  $signals  Human-readable labels of matched patterns.
     */
    public function __construct(
        public bool $suspicious,
        public array $signals,
    ) {}

    public function toArray(): array
    {
        return ['suspicious' => $this->suspicious, 'signals' => $this->signals];
    }
}
