<?php

namespace App\Console\Commands;

use App\Services\ReelPipeline;
use App\Services\TelegramBot;
use Illuminate\Console\Command;

class ReelDaily extends Command
{
    protected $signature = 'reel:daily
        {--date= : Date of the draft (default today)}
        {--type= : ai_facts|tech_news|remi_promo (default: rotates daily)}
        {--theme= : grid|paper|bold|neon|terminal (default: rotates daily)}';

    protected $description = 'Research, script, voice and render today\'s reel as a draft awaiting approval';

    public function handle(ReelPipeline $pipeline, TelegramBot $bot): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        if (($pipeline->manifest($date)['status'] ?? null) === 'posted') {
            $this->warn("Reel for {$date} is already posted.");
            return self::SUCCESS;
        }

        try {
            $m = $pipeline->generate($date, $this->option('type'), $this->option('theme'), fn ($type, $buf) => $this->output->write($buf));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            rescue(fn () => $bot->send("⚠️ Reel {$date} nahi ban paya:
" . mb_substr($e->getMessage(), 0, 500)));
            return self::FAILURE;
        }

        $this->info("Draft ready: {$m['topic']}");
        rescue(fn () => $bot->sendDraft($m));
        $this->line('Review it at ' . url('/reels') . " or run: php artisan reel:approve {$date}");
        return self::SUCCESS;
    }
}
