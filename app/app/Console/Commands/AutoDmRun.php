<?php

namespace App\Console\Commands;

use App\Services\AutoDm;
use App\Services\TelegramBot;
use Illuminate\Console\Command;

class AutoDmRun extends Command
{
    protected $signature = 'autodm:run';

    protected $description = 'DM everyone who commented a rule keyword on recent posts';

    public function handle(AutoDm $dm, TelegramBot $bot): int
    {
        $handled = $dm->run();
        foreach ($handled as [$user, $keyword, $ok, $error]) {
            $this->line(($ok ? 'DM sent' : 'DM failed') . " @{$user} ({$keyword})" . ($error ? ": {$error}" : ''));
        }
        if ($handled) {
            $sent = count(array_filter($handled, fn ($h) => $h[2]));
            $failed = array_filter($handled, fn ($h) => !$h[2]);
            $msg = "💬 Auto-DM: {$sent} bheje";
            if ($failed) {
                $msg .= ', ' . count($failed) . " fail\n" . implode("\n", array_map(fn ($h) => "@{$h[0]}: {$h[3]}", array_slice($failed, 0, 3)));
            }
            rescue(fn () => $bot->send($msg), null, false);
        }
        return self::SUCCESS;
    }
}
