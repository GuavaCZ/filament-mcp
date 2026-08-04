<?php

namespace Guava\FilamentMcp\Support;

use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;

/**
 * A throwaway Livewire component used as the host when building Filament
 * schemas headlessly. Component closures that inject $livewire would
 * otherwise fail against a null host.
 */
class SchemaHost extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public function render(): string
    {
        return '<div></div>';
    }
}
