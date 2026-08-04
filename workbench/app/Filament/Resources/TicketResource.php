<?php

namespace Workbench\App\Filament\Resources;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Workbench\App\Models\Ticket;

/**
 * Registered on the tenant panel's McpPlugin but not in the panel's own
 * ->resources([]), so nothing in Filament wires tenancy for it.
 */
class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('subject')
                    ->required()
                    ->maxLength(255),
                // A singular relationship select, which writes a foreign key onto
                // the record rather than syncing. Project is tenant-scoped.
                Select::make('project_id')
                    ->relationship('project', 'name'),
            ])
        ;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject'),
            ])
        ;
    }
}
