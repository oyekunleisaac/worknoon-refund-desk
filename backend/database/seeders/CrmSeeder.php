<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Order;
use App\Support\DemoCatalog;
use Illuminate\Database\Seeder;

/**
 * Seeds the mock CRM (customers, orders, items). Idempotent: re-running it
 * restores every customer/order to its original state.
 */
class CrmSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach (DemoCatalog::customers() as $data) {
            $customer = Customer::updateOrCreate(
                ['email' => $data['email']],
                [
                    'customer_number' => $data['customer_number'],
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'tier' => $data['tier'],
                    'account_flagged' => isset($data['flagged']),
                    'flag_reason' => $data['flagged'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'customer_since' => $now->copy()->subDays($data['since_days']),
                ],
            );

            foreach ($data['orders'] as [$number, $status, $carrier, $placed, $shipped, $delivered, $items]) {
                $total = collect($items)->sum(fn ($i) => $i[3] * $i[4]);

                $order = Order::updateOrCreate(
                    ['order_number' => $number],
                    [
                        'customer_id' => $customer->id,
                        'status' => $status,
                        'carrier_status' => $carrier,
                        'total' => $total,
                        'currency' => 'USD',
                        'payment_method' => 'card',
                        'placed_at' => $now->copy()->subDays($placed),
                        'shipped_at' => $shipped !== null ? $now->copy()->subDays($shipped) : null,
                        'delivered_at' => $delivered !== null ? $now->copy()->subDays($delivered) : null,
                    ],
                );

                $order->items()->delete();

                foreach ($items as $item) {
                    [$sku, $name, $category, $qty, $price] = $item;
                    $order->items()->create([
                        'sku' => $sku,
                        'name' => $name,
                        'category' => $category,
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'final_sale' => $item[5] ?? false,
                        'refunded_at' => isset($item[6]) ? $now->copy()->subDays($item[6]) : null,
                    ]);
                }
            }
        }
    }
}
