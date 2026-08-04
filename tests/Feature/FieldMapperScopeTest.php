<?php

use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Guava\FilamentMcp\McpPlugin;
use Guava\FilamentMcp\Schema\FieldMapperRegistry;
use Guava\FilamentMcp\Schema\FieldMappers\TextInputMapper;
use Workbench\App\Mcp\ShoutingTextMapper;

it('keeps a panels mapper registration to that panel', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    McpPlugin::get()->fieldMapper(TextInput::class, ShoutingTextMapper::class);

    expect(app(FieldMapperRegistry::class)->mapperFor(TextInput::make('x')))
        ->toBeInstanceOf(ShoutingTextMapper::class)
    ;

    // The tenant panel registered no mapper of its own, so it must still get
    // the built-in one rather than inheriting the admin panel's.
    Filament::setCurrentPanel(Filament::getPanel('tenant'));

    expect(app(FieldMapperRegistry::class)->mapperFor(TextInput::make('x')))
        ->toBeInstanceOf(TextInputMapper::class)
    ;
});

it('still applies an application-wide registration to every panel', function () {
    app(FieldMapperRegistry::class)->register(TextInput::class, ShoutingTextMapper::class);

    foreach (['admin', 'tenant'] as $panelId) {
        Filament::setCurrentPanel(Filament::getPanel($panelId));

        expect(app(FieldMapperRegistry::class)->mapperFor(TextInput::make('x')))
            ->toBeInstanceOf(ShoutingTextMapper::class)
        ;
    }
});
