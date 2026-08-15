<?php

use App\Services\CustomerDiscountService;

it('calculates a percent discount from the amount remaining after automatic discounts', function () {
    $result = app(CustomerDiscountService::class)->calculate(450_000, 'percent', 10);

    expect($result)
        ->toMatchArray([
            'type'   => 'percent',
            'value'  => 10.0,
            'amount' => 45_000.0,
            'total'  => 405_000.0,
        ]);
});

it('caps percent and fixed discounts at the base amount', function (string $type, float $value) {
    $result = app(CustomerDiscountService::class)->calculate(100_000, $type, $value);

    expect($result['amount'])->toBe(100_000.0)
        ->and($result['total'])->toBe(0.0);
})->with([
    'percent' => ['percent', 150],
    'fixed'   => ['fixed', 150_000],
]);

it('ignores invalid and empty discounts', function (mixed $type, mixed $value) {
    $result = app(CustomerDiscountService::class)->calculate(100_000, $type, $value);

    expect($result['type'])->toBeNull()
        ->and($result['amount'])->toBe(0.0)
        ->and($result['total'])->toBe(100_000.0);
})->with([
    'invalid type' => ['unknown', 10],
    'empty value'  => ['percent', null],
    'zero value'   => ['fixed', 0],
]);
