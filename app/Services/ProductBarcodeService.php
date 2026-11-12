<?php

namespace App\Services;

use App\Models\Product;

class ProductBarcodeService
{
    public function findProduct(string $barcode): ?Product
    {
        $barcode = trim($barcode);

        if ($barcode === '') {
            return null;
        }

        $product = Product::query()->where('barcode', $barcode)->first();

        if ($product || !$this->isValidEan13($barcode)) {
            return $product;
        }

        return Product::query()->where('barcode', substr($barcode, 0, 12))->first();
    }

    public function labelType(string $barcode): string
    {
        return $this->isValidEan13($barcode) ? 'EAN13' : 'C128';
    }

    private function isValidEan13(string $barcode): bool
    {
        if (strlen($barcode) !== 13 || !ctype_digit($barcode)) {
            return false;
        }

        $sum = 0;

        for ($index = 0; $index < 12; $index++) {
            $sum += (int) $barcode[$index] * ($index % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10 === (int) $barcode[12];
    }
}
