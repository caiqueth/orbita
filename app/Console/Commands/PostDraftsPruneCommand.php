<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PostDraft;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PostDraftsPruneCommand extends Command
{
    protected $signature = 'orbita:post-drafts-prune
        {--days= : Delete drafts not updated for this many days (default: config orbita.posts.draft_ttl_days)}
        {--dry-run : Report how many drafts would be deleted without changing anything}';

    protected $description = 'Delete stale auto-saved post drafts';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('orbita.posts.draft_ttl_days', 30));
        $query = PostDraft::query()->where('updated_at', '<', Carbon::now()->subDays($days));

        if ($this->option('dry-run')) {
            $this->line("Would delete {$query->count()} drafts older than {$days} days");

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} drafts older than {$days} days");

        return self::SUCCESS;
    }
}
