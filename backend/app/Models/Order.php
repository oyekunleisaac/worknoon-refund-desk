<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total' => 'decimal:2',
            'placed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    public static function normalizeNumber(string $number): string
    {
        return strtoupper(trim($number));
    }

    /** Re-derive the order status after items have been refunded. */
    public function syncRefundStatus(): void
    {
        $items = $this->items()->get();
        $refunded = $items->whereNotNull('refunded_at')->count();

        if ($refunded === 0) {
            return;
        }

        $this->update([
            'status' => $refunded === $items->count() ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded,
        ]);
    }
}
