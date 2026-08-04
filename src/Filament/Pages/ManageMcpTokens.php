<?php

namespace Guava\FilamentMcp\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Guava\FilamentMcp\McpPlugin;
use UnitEnum;

/**
 * A shell around the {@see \Guava\FilamentMcp\Livewire\ManageMcpTokens} component.
 *
 * Extend it and hand the subclass to `McpPlugin::tokensPage()` to change the
 * slug, navigation or title.
 */
class ManageMcpTokens extends Page
{
    protected static ?string $slug = 'mcp-tokens';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-key';

    protected string $view = 'filament-mcp::pages.manage-mcp-tokens';

    public static function canAccess(): bool
    {
        return McpPlugin::get()->hasTokensPage();
    }

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ?? __('filament-mcp::tokens.page.navigation_label');
    }

    public function getTitle(): string
    {
        return static::$title ?? __('filament-mcp::tokens.page.title');
    }

    public static function getNavigationGroup(): string | UnitEnum | null
    {
        return static::$navigationGroup ?? McpPlugin::get()->getTokensNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return static::$navigationSort ?? McpPlugin::get()->getTokensNavigationSort();
    }
}
