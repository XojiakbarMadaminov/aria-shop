<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        @if ($this->productId)
            <div class="flex flex-wrap gap-3">
                <x-filament::button type="submit" icon="heroicon-o-check">
                    Saqlash
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-o-printer"
                    wire:click="saveAndPrint"
                    wire:loading.attr="disabled"
                    wire:target="saveAndPrint"
                >
                    Saqlash va chop etish
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    tag="a"
                    :href="\App\Filament\Resources\Products\ProductResource::getUrl('index')"
                >
                    Bekor qilish
                </x-filament::button>
            </div>
        @endif
    </form>
</x-filament-panels::page>
