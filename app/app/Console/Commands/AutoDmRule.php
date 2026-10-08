<?php

namespace App\Console\Commands;

use App\Services\AutoDm;
use Illuminate\Console\Command;

class AutoDmRule extends Command
{
    protected $signature = 'autodm:rule
        {keyword? : Word people comment, e.g. CODE (omit to list rules)}
        {--message= : DM text sent to the commenter}
        {--reply= : Public reply under the comment (optional)}
        {--media=* : Permalink shortcode to limit to one post (default: all recent posts)}
        {--remove : Delete the rule for this keyword}';

    protected $description = 'Add, list or remove comment-to-DM rules';

    public function handle(AutoDm $dm): int
    {
        $keyword = $this->argument('keyword');
        if (!$keyword) {
            $this->table(['Keyword', 'Post', 'Public reply', 'DM'], array_map(
                fn ($r) => [$r['keyword'], $r['media'], $r['reply'], mb_strimwidth($r['message'], 0, 60, '…')],
                $dm->state()['rules'],
            ));
            return self::SUCCESS;
        }
        if ($this->option('remove')) {
            $dm->removeRule($keyword);
            $this->info("Removed rule {$keyword}");
            return self::SUCCESS;
        }
        if (!$this->option('message')) {
            $this->error('--message is required');
            return self::FAILURE;
        }
        $dm->setRule($keyword, $this->option('message'), (string) $this->option('reply'), $this->option('media')[0] ?? '*');
        $this->info("Rule saved: comment \"{$keyword}\" → DM");
        return self::SUCCESS;
    }
}
