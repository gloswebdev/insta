<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Daily reel drafts live in <project_dir>/out/daily/<date>/ with a manifest.json:
 *   pending  → generated, waiting for approval
 *   posted   → uploaded + published to Instagram
 *   rejected → skipped by the owner
 *   failed   → pipeline error (see "error")
 */
class ReelPipeline
{
    public function __construct(private InstagramService $ig) {}

    public function dir(string $date): string
    {
        return config('reels.project_dir') . "/out/daily/{$date}";
    }

    public function manifest(string $date): ?array
    {
        $file = $this->dir($date) . '/manifest.json';
        return is_file($file) ? json_decode(file_get_contents($file), true) : null;
    }

    public function drafts(): array
    {
        $dirs = glob(config('reels.project_dir') . '/out/daily/*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        return array_values(array_filter(array_map(fn ($d) => $this->manifest(basename($d)), $dirs)));
    }

    /** Runs research → script → voice-over → render. Returns the manifest. */
    public function generate(string $date, ?string $type = null, ?string $theme = null, ?callable $output = null): array
    {
        $cmd = [config('reels.npx'), 'tsx', 'pipeline/daily.ts', '--date', $date];
        if ($type) {
            array_push($cmd, '--type', $type);
        }
        if ($theme) {
            array_push($cmd, '--theme', $theme);
        }
        $env = array_filter(config('reels.env'));
        $env['PATH'] = config('reels.path_extra') . ';' . getenv('PATH');
        $process = new Process($cmd, config('reels.project_dir'), $env, null, 3600);
        $process->run($output);

        $manifest = $this->manifest($date);
        if (!$process->isSuccessful() || ($manifest['status'] ?? null) !== 'pending') {
            throw new RuntimeException($manifest['error'] ?? trim($process->getErrorOutput()) ?: 'Pipeline failed');
        }
        return $manifest;
    }

    /** Uploads the approved reel to the public host and publishes it. */
    public function publish(string $date): array
    {
        $m = $this->manifest($date);
        if (($m['status'] ?? null) !== 'pending') {
            throw new RuntimeException("Reel {$date} is not pending (status: " . ($m['status'] ?? 'missing') . ')');
        }
        if (!config('reels.public_url')) {
            throw new RuntimeException('REEL_PUBLIC_URL is not set');
        }

        $name = "{$date}.mp4";
        $stream = fopen($m['file'], 'r');
        Storage::disk('reels')->put($name, $stream);
        is_resource($stream) && fclose($stream);
        $url = config('reels.public_url') . "/{$name}";

        $head = Http::timeout(30)->head($url);
        if (!$head->successful()) {
            throw new RuntimeException("Uploaded file is not reachable at {$url} (HTTP {$head->status()})");
        }

        $res = $this->ig->postReel($url, $m['caption']);
        $media = Http::get(config('instagram.base_url') . "/{$res['id']}", [
            'fields'       => 'permalink',
            'access_token' => $this->ig->token(),
        ])->json();

        return $this->update($date, [
            'status'    => 'posted',
            'media_id'  => $res['id'],
            'permalink' => $media['permalink'] ?? null,
            'video_url' => $url,
            'postedAt'  => now()->toIso8601String(),
        ]);
    }

    public function reject(string $date): array
    {
        return $this->update($date, ['status' => 'rejected']);
    }

    private function update(string $date, array $fields): array
    {
        $m = array_merge($this->manifest($date) ?? [], $fields);
        file_put_contents($this->dir($date) . '/manifest.json', json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $m;
    }
}
