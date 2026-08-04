<?php

namespace Guava\FilamentMcp\Support;

use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Livewire\Component;

/**
 * A throwaway Livewire component used as the host when building Filament
 * tables headlessly, e.g. to discover a resource's table actions.
 */
class TableHost extends Component implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function render(): string
    {
        return '<div></div>';
    }
}
