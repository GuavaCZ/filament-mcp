<?php

namespace Guava\FilamentMcp\Commands;

use Guava\FilamentMcp\Models\McpUpload;
use Illuminate\Console\Command;

class PruneUploadsCommand extends Command
{
    protected $signature = 'mcp:prune-uploads
        {--keep-consumed= : Keep consumed uploads for the given number of days instead of deleting them now}';

    protected $description = 'Delete expired and already-claimed Filament MCP upload staging files';

    public function handle(): int
    {
        $deleted = 0;

        $query = McpUpload::query()
            ->where('expires_at', '<', now())
        ;

        if ($days = $this->option('keep-consumed')) {
            $query->orWhere('consumed_at', '<', now()->subDays((int) $days));
        } else {
            $query->orWhereNotNull('consumed_at');
        }

        // Chunked and deleted one by one, since the staged file has to go with
        // the row or pruning leaves orphaned bytes on the disk.
        $query->chunkById(100, function ($uploads) use (&$deleted): void {
            foreach ($uploads as $upload) {
                $upload->deleteFile();
                $upload->delete();
                $deleted++;
            }
        });

        $this->info("Pruned {$deleted} MCP upload(s).");

        return static::SUCCESS;
    }
}
