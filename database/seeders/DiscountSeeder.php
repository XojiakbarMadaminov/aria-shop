<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\Discount;
use App\Enums\DiscountType;
use Illuminate\Database\Seeder;

class DiscountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Store::query()
            ->select('id')
            ->each(function (Store $store): void {
                foreach ($this->discounts() as $discount) {
                    Discount::query()->firstOrCreate(
                        [
                            'store_id' => $store->id,
                            'type'     => $discount['type'],
                        ],
                        $discount,
                    );
                }
            });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function discounts(): array
    {
        return [
            [
                'name'             => 'Tanlangan tovarlarga chegirma',
                'type'             => DiscountType::SelectedProductsPercent->value,
                'percent'          => 1,
                'min_order_amount' => null,
                'starts_at'        => null,
                'ends_at'          => null,
                'is_active'        => false,
            ],
            [
                'name'             => "Kategoriya bo'yicha chegirma",
                'type'             => DiscountType::CategoryPercent->value,
                'percent'          => 1,
                'min_order_amount' => null,
                'starts_at'        => null,
                'ends_at'          => null,
                'is_active'        => false,
            ],
            [
                'name'             => 'Barcha tovarlarga chegirma',
                'type'             => DiscountType::GlobalPercent->value,
                'percent'          => 1,
                'min_order_amount' => null,
                'starts_at'        => null,
                'ends_at'          => null,
                'is_active'        => false,
            ],
            [
                'name'             => 'Buyurtma summasiga chegirma',
                'type'             => DiscountType::OrderAmountPercent->value,
                'percent'          => 1,
                'min_order_amount' => 0,
                'starts_at'        => null,
                'ends_at'          => null,
                'is_active'        => false,
            ],
        ];
    }
}
