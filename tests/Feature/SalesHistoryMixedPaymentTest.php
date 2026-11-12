<?php

use App\Models\Sale;
use App\Models\User;
use App\Models\Store;
use Livewire\Livewire;
use Filament\Facades\Filament;
use App\Filament\Pages\SalesHistoryPage;
use Filament\Actions\Testing\TestAction;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $store = Store::create(['name' => 'Test store', 'address' => 'Tashkent', 'phone' => '+998901234567']);
    $user  = User::factory()->create(['current_store_id' => $store->id]);
    $user->stores()->attach($store);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::firstOrCreate(['name' => 'View:SalesHistoryPage', 'guard_name' => 'web']);
    $user->givePermissionTo('View:SalesHistoryPage');
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('shows both payment amounts in the mixed sale details modal', function (float $cash, float $card) {
    $sale = Sale::withoutEvents(fn (): Sale => Sale::create([
        'store_id'          => auth()->user()->current_store_id,
        'cart_id'           => 1,
        'total_amount'      => 150000,
        'paid_amount'       => 150000,
        'payment_type'      => 'mixed',
        'mixed_cash_amount' => $cash,
        'mixed_card_amount' => $card,
    ]));

    $component = Livewire::test(SalesHistoryPage::class)
        ->mountAction(TestAction::make('view_details')->table($sale))
        ->assertActionMounted(TestAction::make('view_details')->table($sale));

    $action = $component->instance()->getMountedAction();

    expect($action->getModalHeading())->toBe('Sotuv tafsilotlari')
        ->and($action->getModalContent()->render())
        ->toContain('Naqd to‘lov')
        ->toContain('Karta to‘lov')
        ->toContain(number_format($cash, 2))
        ->toContain(number_format($card, 2));
})->with([
    'split payment' => [100000.0, 50000.0],
    'all cash'      => [150000.0, 0.0],
    'all card'      => [0.0, 150000.0],
]);

it('keeps the sale details unchanged for other payment types', function (string $paymentType) {
    $sale = Sale::withoutEvents(fn (): Sale => Sale::create([
        'store_id'          => auth()->user()->current_store_id,
        'cart_id'           => 1,
        'total_amount'      => 150000,
        'paid_amount'       => 150000,
        'payment_type'      => $paymentType,
        'mixed_cash_amount' => 100000,
        'mixed_card_amount' => 50000,
    ]));

    $component = Livewire::test(SalesHistoryPage::class)
        ->mountAction(TestAction::make('view_details')->table($sale))
        ->assertActionMounted(TestAction::make('view_details')->table($sale));

    $action = $component->instance()->getMountedAction();

    expect($action->getModalHeading())->toBe('Sotuv tafsilotlari')
        ->and($action->getModalContent()->render())
        ->toContain('Jami summa')
        ->not->toContain('Naqd to‘lov')
        ->not->toContain('Karta to‘lov');
})->with(['cash', 'card', 'debt', 'partial', 'transfer', 'preorder']);
