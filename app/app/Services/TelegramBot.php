<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Telegram Bot API client used for approving daily reels from the phone.
 * The owner's chat id is captured on their first /start and stored in storage/app/telegram.json.
 */
class TelegramBot
{
    private string $base;
    private string $stateFile;

    public function __construct()
    {
        $this->base = 'https://api.telegram.org/bot' . config('reels.telegram_token');
        $this->stateFile = storage_path('app/telegram.json');
    }

    public function enabled(): bool
    {
        return (bool) config('reels.telegram_token');
    }

    public function state(): array
    {
        return is_file($this->stateFile) ? json_decode(file_get_contents($this->stateFile), true) : [];
    }

    public function saveState(array $fields): void
    {
        file_put_contents($this->stateFile, json_encode(array_merge($this->state(), $fields)));
    }

    public function ownerChatId(): ?int
    {
        return $this->state()['chat_id'] ?? null;
    }

    public function call(string $method, array $params = [], ?array $file = null): array
    {
        $req = Http::timeout($file ? 600 : 120);
        if ($file) {
            $req = $req->attach($file[0], fopen($file[1], 'r'), basename($file[1]));
        }
        try {
            $res = $file ? $req->post("{$this->base}/{$method}", $params) : $req->asJson()->post("{$this->base}/{$method}", $params);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // The request URL contains the bot token; never let it reach logs or chat.
            throw new RuntimeException("Telegram {$method}: network error (" . str_replace(config('reels.telegram_token'), '***', $e->getMessage()) . ')');
        }
        $json = $res->json();
        if (!($json['ok'] ?? false)) {
            throw new RuntimeException("Telegram {$method}: " . ($json['description'] ?? $res->status()));
        }
        return $json['result'];
    }

    public function send(string $text, array $extra = [], ?int $chatId = null): void
    {
        $chatId ??= $this->ownerChatId();
        if ($chatId) {
            $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true] + $extra);
        }
    }

    /** Sends up to 10 local images as one album (a single image as a normal photo). */
    public function sendPhotos(array $paths): void
    {
        $chatId = $this->ownerChatId();
        if (!$chatId || !$paths) {
            return;
        }
        if (count($paths) === 1) {
            $this->call('sendPhoto', ['chat_id' => $chatId], ['photo', $paths[0]]);
            return;
        }
        $req = Http::timeout(180);
        $media = [];
        foreach (array_slice(array_values($paths), 0, 10) as $i => $p) {
            $req = $req->attach("f{$i}", fopen($p, 'r'), basename($p));
            $media[] = ['type' => 'photo', 'media' => "attach://f{$i}"];
        }
        $json = $req->post("{$this->base}/sendMediaGroup", ['chat_id' => $chatId, 'media' => json_encode($media)])->json();
        if (!($json['ok'] ?? false)) {
            throw new RuntimeException('Telegram sendMediaGroup: ' . ($json['description'] ?? 'failed'));
        }
    }

    /** A small 720p copy of the reel for the Telegram preview (the full file is what gets posted). */
    private function preview(string $file): string
    {
        $out = preg_replace('/\.mp4$/', '', $file) . '-preview.mp4';
        if (!is_file($out) || filemtime($out) < filemtime($file)) {
            $ffmpeg = 'ffmpeg';
            foreach (explode(';', config('reels.path_extra')) as $dir) {
                is_file("{$dir}/ffmpeg.exe") && $ffmpeg = "{$dir}/ffmpeg.exe";
            }
            (new \Symfony\Component\Process\Process([$ffmpeg, '-v', 'error', '-y', '-i', $file, '-vf', 'scale=720:-2', '-c:v', 'libx264', '-crf', '28', '-preset', 'veryfast', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $out], null, null, null, 300))->mustRun();
        }
        return $out;
    }

    /** Sends a pending draft with Approve / Reject buttons. */
    public function sendDraft(array $m): void
    {
        $chatId = $this->ownerChatId();
        if (!$chatId) {
            return;
        }
        $warn = ($m['verified'] ?? true) ? '' : "⚠️ UNVERIFIED — facts web search se check nahi hue, post se pehle khud dekh lo.\n\n";
        $sources = implode("\n", array_slice($m['sources'] ?? [], 0, 4));
        $text = "🎬 Reel draft {$m['date']} ({$m['type']})\n{$m['topic']}\n\n{$warn}Caption:\n{$m['caption']}" . ($sources ? "\n\nSources:\n{$sources}" : '');

        $keyboard = json_encode(['inline_keyboard' => [[
            ['text' => '✅ Post karo', 'callback_data' => "approve:{$m['date']}"],
            ['text' => '❌ Reject', 'callback_data' => "reject:{$m['date']}"],
        ]]]);

        // Video captions are capped at 1024 chars, so the details go in a separate message.
        $this->call('sendVideo', ['chat_id' => $chatId, 'caption' => "🎬 {$m['topic']}", 'supports_streaming' => 'true'], ['video', $this->preview($m['file'])]);
        $this->call('sendMessage', ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 4000), 'disable_web_page_preview' => true, 'reply_markup' => json_decode($keyboard, true)]);
    }
}
