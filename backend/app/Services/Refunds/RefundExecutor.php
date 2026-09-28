<?php

namespace App\Services\Refunds;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Applies an approved refund to the order record. In production this is
 * where the payment provider call would go (idempotency key = request
 * reference); here it marks items refunded and re-derives order status.
 */
class RefundExecutor
{
    /**
     * @param  list<int>  $itemIds
     */
    public function execute(Order $order, array $itemIds): float
    {
        return DB::transaction(function () use ($order, $itemIds) {
            $items = OrderItem::query()
                ->where('order_id', $order->id)
                ->whereIn('id', $itemIds)
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($itemIds) || $items->contains(fn (OrderItem $i) => $i->isRefunded())) {
                throw new RuntimeException("Items on order {$order->order_number} changed or were already refunded.");
            }

            OrderItem::query()->whereIn('id', $items->pluck('id'))->update(['refunded_at' => now()]);
            $order->syncRefundStatus();

            return round($items->sum(fn (OrderItem $i) => $i->lineTotal()), 2);
        });
    }
}
