<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'account_flagged' => 'boolean',
            'customer_since' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    public function firstName(): string
    {
        return strtok($this->name, ' ') ?: $this->name;
    }

    /** Number of distinct orders that received a refund within the lookback window. */
    public function refundedOrdersSince(\DateTimeInterface $since): int
    {
        return OrderItem::query()
            ->whereIn('order_id', $this->orders()->select('id'))
            ->where('refunded_at', '>=', $since)
            ->distinct()
            ->count('order_id');
    }
}
