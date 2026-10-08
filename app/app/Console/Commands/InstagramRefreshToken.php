<?php

namespace App\Console\Commands;

use App\Services\InstagramService;
use Illuminate\Console\Command;

class InstagramRefreshToken extends Command
{
    protected $signature   = 'instagram:refresh-token';
    protected $description = 'Refresh the long-lived Instagram access token (run weekly)';

    public function handle(InstagramService $ig): int
    {
        $res = $ig->refreshToken();
        $days = isset($res['expires_in']) ? round($res['expires_in'] / 86400) : '?';
        $this->info("Token refreshed. Valid for about {$days} days.");
        return self::SUCCESS;
    }
}
