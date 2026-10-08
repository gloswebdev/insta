<?php

namespace App\Console\Commands;

use App\Services\CarouselMaker;
use App\Services\ReelPipeline;
use App\Services\TelegramBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ReelTelegram extends Command
{
    protected $signature = 'reel:telegram';

    protected $description = 'Handle Telegram bot updates: reel approvals, and images sent to the bot turned into carousel posts';

    /** Seconds without a new image before the carousel is considered complete. Images sent
     *  one by one within this window join the same carousel, like an album. */
    private const ALBUM_SETTLE = 12;

    public function handle(TelegramBot $bot, ReelPipeline $pipeline, CarouselMaker $carousels): int
    {
        if (!$bot->enabled()) {
            return self::SUCCESS;
        }

        // Long-poll for ~50s so button presses get answered within a second or two;
        // the scheduler starts this every minute (withoutOverlapping).
        $until = time() + 50;
        while (time() < $until) {
            $updates = $bot->call('getUpdates', ['offset' => $bot->state()['offset'] ?? 0, 'timeout' => max(1, min(5, $until - time()))]);
            foreach ($updates as $update) {
                $bot->saveState(['offset' => $update['update_id'] + 1]);
                try {
                    $this->process($bot, $pipeline, $carousels, $update);
                } catch (\Throwable $e) {
                    report($e);
                    rescue(fn () => $bot->send('⚠️ ' . mb_substr($e->getMessage(), 0, 500)));
                }
            }
            $this->finishAlbums($bot, $carousels);
        }
        return self::SUCCESS;
    }

    private function process(TelegramBot $bot, ReelPipeline $pipeline, CarouselMaker $carousels, array $update): void
    {
        $owner = $bot->ownerChatId();

        if ($msg = $update['message'] ?? null) {
            $chatId = $msg['chat']['id'];
            $text = trim($msg['text'] ?? '');

            if (str_starts_with($text, '/start') && !$owner) {
                $bot->saveState(['chat_id' => $chatId]);
                $bot->send("✅ Connected! Roz ka reel draft yahan aayega — button dabake post ya reject karna.\n\n📸 Carousel: image(s) bhejo (collage ya album) — main slides bana ke preview bhejunga.\n\nCommands: /status", [], $chatId);
            } elseif ($chatId !== $owner) {
                $bot->send('Ye bot private hai.', [], $chatId);
            } elseif ($msg['photo'] ?? $msg['document'] ?? null) {
                $this->receiveImage($bot, $carousels, $msg);
            } elseif (str_starts_with($text, '/status')) {
                $lines = array_map(fn ($m) => "{$m['date']} · {$m['status']} · " . ($m['topic'] ?? $m['error'] ?? ''), array_slice($pipeline->drafts(), 0, 7));
                $bot->send($lines ? implode("\n", $lines) : 'Abhi koi draft nahi.');
            } elseif ($text !== '' && !str_starts_with($text, '/') && ($draft = last($carousels->withStatus('pending')))) {
                // Plain text while a carousel preview is waiting = its caption
                $carousels->update($draft['id'], ['caption' => $text]);
                $bot->send("✏️ Caption set ho gaya. Ab preview wale message par ✅ Post karo dabao.");
            }
            return;
        }

        if ($cb = $update['callback_query'] ?? null) {
            rescue(fn () => $bot->call('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => 'Theek hai…']), null, false);
            if (($cb['from']['id'] ?? null) !== $owner && ($cb['message']['chat']['id'] ?? null) !== $owner) {
                return;
            }
            [$action, $id] = explode(':', $cb['data'] ?? '', 2) + [null, null];
            // Remove the buttons so nothing can be posted twice
            rescue(fn () => $bot->call('editMessageReplyMarkup', ['chat_id' => $cb['message']['chat']['id'], 'message_id' => $cb['message']['message_id'], 'reply_markup' => ['inline_keyboard' => []]]), null, false);

            match ($action) {
                'approve', 'reject' => $this->respondReel($bot, $pipeline, $action, $id),
                'cpost', 'ccancel' => $this->respondCarousel($bot, $carousels, $action, $id),
                default => null,
            };
        }
    }

    /* ---------- carousels ---------- */

    private function receiveImage(TelegramBot $bot, CarouselMaker $carousels, array $msg): void
    {
        $doc = $msg['document'] ?? null;
        if ($doc && !str_starts_with($doc['mime_type'] ?? '', 'image/')) {
            $bot->send('Sirf images (JPG / PNG / WEBP) bhejo.');
            return;
        }
        $fileId = $doc ? $doc['file_id'] : end($msg['photo'])['file_id'];
        $path = $bot->call('getFile', ['file_id' => $fileId])['file_path'];
        $bytes = Http::timeout(120)->get('https://api.telegram.org/file/bot' . config('reels.telegram_token') . "/{$path}")->body();

        // Join the carousel that is still collecting (album or images sent one after another);
        // otherwise start a new one.
        $open = last($carousels->withStatus('collecting'));
        $id = $open['id'] ?? date('Ymd-His', $msg['date']) . '-' . $msg['message_id'];
        $isNew = !$carousels->manifest($id);
        $carousels->addSource($id, $bytes, pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg', $msg['caption'] ?? null);
        if ($isNew) {
            $bot->send('📥 Image mil gayi — slides bana raha hoon…');
        }
    }

    /** Builds slides for albums that stopped receiving images and sends the preview. */
    private function finishAlbums(TelegramBot $bot, CarouselMaker $carousels): void
    {
        foreach ($carousels->withStatus('collecting') as $m) {
            if (time() - ($m['lastUpdate'] ?? 0) < self::ALBUM_SETTLE) {
                continue;
            }
            try {
                $slides = $carousels->build($m['id']);
            } catch (\Throwable $e) {
                $carousels->update($m['id'], ['status' => 'failed', 'error' => $e->getMessage()]);
                $bot->send('⚠️ Slides nahi ban payi: ' . $e->getMessage());
                continue;
            }

            $bot->sendPhotos($slides);
            $caption = trim($carousels->manifest($m['id'])['caption'] ?? '');
            $kind = count($slides) === 1 ? '1 image post' : count($slides) . ' slides ka carousel';
            $text = "📸 {$kind} ready.\n\n" . ($caption !== ''
                ? "Caption:\n{$caption}\n\nCaption badalna ho to naya text bhej do."
                : "Caption abhi khaali hai — caption ka text bhej do, phir ✅ dabao.");
            $bot->call('sendMessage', [
                'chat_id' => $bot->ownerChatId(),
                'text' => mb_substr($text, 0, 4000),
                'reply_markup' => ['inline_keyboard' => [[
                    ['text' => '✅ Post karo', 'callback_data' => "cpost:{$m['id']}"],
                    ['text' => '❌ Cancel', 'callback_data' => "ccancel:{$m['id']}"],
                ]]],
            ]);
        }
    }

    private function respondCarousel(TelegramBot $bot, CarouselMaker $carousels, string $action, string $id): void
    {
        try {
            if ($action === 'ccancel') {
                $carousels->update($id, ['status' => 'cancelled']);
                $bot->send('❌ Carousel cancel kar diya.');
                return;
            }
            $bot->send('⏳ Carousel post ho raha hai…');
            $m = $carousels->publish($id);
            $bot->send("✅ Posted!\n" . ($m['permalink'] ?? $m['media_id']));
        } catch (\Throwable $e) {
            $bot->send('⚠️ Carousel: ' . mb_substr($e->getMessage(), 0, 500));
        }
    }

    /* ---------- reels ---------- */

    private function respondReel(TelegramBot $bot, ReelPipeline $pipeline, string $action, ?string $date): void
    {
        try {
            if ($action === 'approve') {
                $bot->send("⏳ {$date} post ho raha hai…");
                $m = $pipeline->publish($date);
                $bot->send("✅ Posted!\n" . ($m['permalink'] ?? $m['media_id']));
            } else {
                $pipeline->reject($date);
                $bot->send("❌ {$date} reject kar diya.");
            }
        } catch (\Throwable $e) {
            $bot->send("⚠️ {$date}: {$e->getMessage()}");
        }
    }
}
