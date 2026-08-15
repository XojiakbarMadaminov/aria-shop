<?php

namespace App\Services;

class CustomerDiscountService
{
    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    /**
     * @return array{type: string|null, value: float, amount: float, total: float}
     */
    public function calculate(float $baseAmount, ?string $type, mixed $value): array
    {
        $baseAmount = $this->roundMoney(max(0, $baseAmount));

        if (!in_array($type, [self::TYPE_PERCENT, self::TYPE_FIXED], true) || !is_numeric($value)) {
            return $this->emptyResult($baseAmount);
        }

        $normalizedValue = max(0, (float) $value);

        if ($type === self::TYPE_PERCENT) {
            $normalizedValue = min(100, $normalizedValue);
            $discountAmount  = $this->roundMoney($baseAmount * $normalizedValue / 100);
        } else {
            $normalizedValue = $this->roundMoney(min($baseAmount, $normalizedValue));
            $discountAmount  = $normalizedValue;
        }

        $discountAmount = min($baseAmount, $discountAmount);

        return [
            'type'   => $discountAmount > 0 ? $type : null,
            'value'  => $discountAmount > 0 ? $normalizedValue : 0.0,
            'amount' => $discountAmount,
            'total'  => $this->roundMoney($baseAmount - $discountAmount),
        ];
    }

    /**
     * @return array{type: null, value: float, amount: float, total: float}
     */
    protected function emptyResult(float $baseAmount): array
    {
        return [
            'type'   => null,
            'value'  => 0.0,
            'amount' => 0.0,
            'total'  => $baseAmount,
        ];
    }

    protected function roundMoney(float $amount): float
    {
        return round($amount, 2);
    }
}
