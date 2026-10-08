<?php

namespace App\Console\Commands;

use App\Services\ReelPipeline;
use Illuminate\Console\Command;

class ReelList extends Command
{
    protected $signature = 'reel:list';

    protected $description = 'List daily reel drafts and their status';

    public function handle(ReelPipeline $pipeline): int
    {
        $this->table(['Date', 'Type', 'Status', 'Topic / error', 'Link'], array_map(fn ($m) => [
            $m['date'] ?? '?', $m['type'] ?? '', $m['status'] ?? '', $m['topic'] ?? ($m['error'] ?? ''), $m['permalink'] ?? '',
        ], $pipeline->drafts()));
        return self::SUCCESS;
    }
}
