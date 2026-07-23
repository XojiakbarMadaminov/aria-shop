<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum NavigationGroup implements HasLabel
{
    case BaseActions;
    case ProductsAndCategories;
    case SupplierActions;
    case Finance;
    case Statistics;
    case Settings;
    case Sms;

    public function getLabel(): string
    {
        return match ($this) {
            self::BaseActions           => 'Asosiy amallar',
            self::ProductsAndCategories => 'Tovar va kategoriyalar',
            self::SupplierActions       => 'Ta\'minotlar',
            self::Finance               => 'Moliya',
            self::Settings              => 'Sozlamalar',
            self::Statistics            => 'Hisobot va tahlil',
            self::Sms                   => 'SMS xabarlar',
        };
    }
}
