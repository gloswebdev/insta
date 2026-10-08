<?php

namespace App\Console\Commands;

use App\Services\InstagramService;
use Illuminate\Console\Command;

class InstagramPost extends Command
{
    protected $signature = 'instagram:post
        {type : photo|reel}
        {url : Public URL of the image or video}
        {--caption= : Caption text}';

    protected $description = 'Publish a photo or reel to Instagram (for testing)';

    public function handle(InstagramService $ig): int
    {
        $caption = (string) $this->option('caption');

        $res = match ($this->argument('type')) {
            'photo' => $ig->postPhoto($this->argument('url'), $caption),
            'reel'  => $ig->postReel($this->argument('url'), $caption),
            default => null,
        };

        if (!$res) {
            $this->error('type must be photo or reel');
            return self::FAILURE;
        }

        $this->info('Published. Media ID: ' . $res['id']);
        return self::SUCCESS;
    }
}
