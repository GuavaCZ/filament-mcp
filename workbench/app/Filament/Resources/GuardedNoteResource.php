<?php

namespace Workbench\App\Filament\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;
use Workbench\App\Filament\Resources\GuardedNoteResource\Pages\ManageGuardedNotes;
use Workbench\App\Models\GuardedNote;

class GuardedNoteResource extends Resource
{
    protected static ?string $model = GuardedNote::class;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                // Present on the form, missing from the model's $fillable.
                TextInput::make('secret'),
                // Compiling this field's rules throws, standing in for any
                // rule that needs runtime context the headless schema lacks.
                TextInput::make('explodes')
                    ->required(fn () => throw new RuntimeException('needs runtime context')),
            ])
        ;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGuardedNotes::route('/'),
        ];
    }
}
