<?php

namespace App\Console\Commands;

use App\Services\ReelPipeline;
use Illuminate\Console\Command;

class ReelApprove extends Command
{
    protected $signature = 'reel:approve {date? : Draft date (default today)} {--reject : Skip this draft instead of posting}';

    protected $description = 'Upload and publish an approved reel draft (or reject it)';

    public function handle(ReelPipeline $pipeline): int
    {
        $date = $this->argument('date') ?: now()->toDateString();
        try {
            if ($this->option('reject')) {
                $pipeline->reject($date);
                $this->info("Rejected {$date}.");
                return self::SUCCESS;
            }
            $m = $pipeline->publish($date);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info('Posted: ' . ($m['permalink'] ?? $m['media_id']));
        return self::SUCCESS;
    }
}
