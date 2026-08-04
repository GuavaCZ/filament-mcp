<?php

namespace Guava\FilamentMcp\Filament\Schemas\Components;

use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Guava\FilamentMcp\Livewire\ManageMcpTokens;

/**
 * Drop this into any schema, typically the `content()` of your own `EditProfile`
 * page, to render the token manager there.
 *
 * It is the same Livewire component the standalone page renders, so the two stay
 * in sync.
 */
class McpTokensSection extends Section
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('filament-mcp::tokens.section.heading'))
            ->description(__('filament-mcp::tokens.section.description'))
            ->visible(fn (): bool => ManageMcpTokens::canAccess())
            ->schema([
                Livewire::make(ManageMcpTokens::class),
            ])
        ;
    }
}
