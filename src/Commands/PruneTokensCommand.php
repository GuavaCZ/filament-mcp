<?php

namespace Guava\FilamentMcp\Commands;

use Guava\FilamentMcp\Models\McpToken;
use Illuminate\Console\Command;

class PruneTokensCommand extends Command
{
    protected $signature = 'mcp:prune-tokens
        {--unused-for= : Also delete tokens not used for the given number of days}';

    protected $description = 'Delete expired (and optionally stale) Filament MCP tokens';

    public function handle(): int
    {
        $deleted = McpToken::query()
            ->where('expires_at', '<', now())
            ->delete()
        ;

        if ($days = $this->option('unused-for')) {
            $cutoff = now()->subDays((int) $days);

            $deleted += McpToken::query()
                ->where(fn ($query) => $query
                    ->where('last_used_at', '<', $cutoff)
                    ->orWhere(fn ($query) => $query
                        ->whereNull('last_used_at')
                        ->where('created_at', '<', $cutoff)))
                ->delete()
            ;
        }

        $this->info("Pruned {$deleted} MCP token(s).");

        return static::SUCCESS;
    }
}
