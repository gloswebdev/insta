<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Turns images sent to the Telegram bot into an Instagram carousel draft.
 *
 * Drafts live in storage/app/carousels/<id>/: source images in src/, finished 1080×1350
 * slides as slide-N.jpg, and manifest.json { id, caption, status: collecting|pending|posted|cancelled }.
 * A collage (several panels separated by light gutters) is split into one slide per panel.
 */
class CarouselMaker
{
    private const W = 1080;
    private const H = 1350;

    public function __construct(private InstagramService $ig) {}

    public function dir(string $id): string
    {
        return storage_path("app/carousels/{$id}");
    }

    public function manifest(string $id): ?array
    {
        $f = $this->dir($id) . '/manifest.json';
        return is_file($f) ? json_decode(file_get_contents($f), true) : null;
    }

    public function update(string $id, array $fields): array
    {
        $m = array_merge($this->manifest($id) ?? ['id' => $id], $fields);
        is_dir($this->dir($id)) || mkdir($this->dir($id), 0777, true);
        file_put_contents($this->dir($id) . '/manifest.json', json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $m;
    }

    /** All drafts with the given status, oldest first. */
    public function withStatus(string $status): array
    {
        $all = array_map(fn ($d) => $this->manifest(basename($d)), glob(storage_path('app/carousels/*'), GLOB_ONLYDIR) ?: []);
        $hits = array_values(array_filter($all, fn ($m) => ($m['status'] ?? null) === $status));
        usort($hits, fn ($a, $b) => strcmp($a['id'], $b['id']));
        return $hits;
    }

    /** Adds one received image to a collecting draft (albums share an id). */
    public function addSource(string $id, string $bytes, string $ext, ?string $caption): void
    {
        $src = $this->dir($id) . '/src';
        is_dir($src) || mkdir($src, 0777, true);
        $n = count(glob("{$src}/*")) + 1;
        file_put_contents(sprintf('%s/%02d.%s', $src, $n, $ext), $bytes);
        $m = $this->manifest($id) ?? ['id' => $id, 'caption' => '', 'status' => 'collecting'];
        $this->update($id, [
            'status'     => 'collecting',
            'caption'    => $caption !== null && $caption !== '' ? $caption : ($m['caption'] ?? ''),
            'lastUpdate' => time(),
        ]);
    }

    /** Builds the slides for a draft. Returns the slide paths. */
    public function build(string $id): array
    {
        $dir = $this->dir($id);
        array_map('unlink', glob("{$dir}/slide-*.jpg") ?: []);
        $sources = glob("{$dir}/src/*") ?: [];
        sort($sources);

        $panels = [];
        foreach ($sources as $file) {
            // Several images in an album are used as-is; a single image may be a collage.
            foreach (count($sources) === 1 ? $this->panels($file) : [null] as $rect) {
                $panels[] = [$file, $rect];
            }
        }
        if (count($panels) > 10) {
            throw new RuntimeException('Instagram carousel mein max 10 slides hoti hain — ' . count($panels) . ' mili.');
        }

        $slides = [];
        foreach ($panels as $i => [$file, $rect]) {
            $out = sprintf('%s/slide-%d.jpg', $dir, $i + 1);
            $crop = $rect ? sprintf('crop=%d:%d:%d:%d,', $rect[2], $rect[3], $rect[0], $rect[1]) : '';
            $W = self::W;
            $H = self::H;
            $filter = "[0]{$crop}split[a][b];"
                . "[a]scale={$W}:{$H}:force_original_aspect_ratio=increase,crop={$W}:{$H},gblur=sigma=45,eq=brightness=-0.12[bg];"
                . "[b]scale=" . ($W - 40) . ':' . ($H - 40) . ":force_original_aspect_ratio=decrease:flags=lanczos[fg];"
                . '[bg][fg]overlay=(W-w)/2:(H-h)/2';
            $p = new Process([$this->ffmpeg(), '-v', 'error', '-y', '-i', $file, '-filter_complex', $filter, '-q:v', '2', $out]);
            $p->mustRun();
            $slides[] = $out;
        }
        $this->update($id, ['status' => 'pending', 'slides' => count($slides)]);
        return $slides;
    }

    /** Uploads the slides and publishes them (a single slide is posted as a photo). */
    public function publish(string $id): array
    {
        $m = $this->manifest($id);
        if (($m['status'] ?? null) !== 'pending') {
            throw new RuntimeException("Carousel {$id} pending nahi hai (status: " . ($m['status'] ?? 'missing') . ')');
        }
        $urls = [];
        foreach (glob($this->dir($id) . '/slide-*.jpg') as $i => $file) {
            $name = "carousels/{$id}/" . basename($file);
            $stream = fopen($file, 'r');
            Storage::disk('reels')->put($name, $stream);
            is_resource($stream) && fclose($stream);
            $urls[] = config('reels.public_url') . "/{$name}";
        }
        natsort($urls);
        $urls = array_values($urls);

        $res = count($urls) === 1
            ? $this->ig->postPhoto($urls[0], $m['caption'] ?? '')
            : $this->ig->postCarousel(array_map(fn ($u) => ['type' => 'image', 'url' => $u], $urls), $m['caption'] ?? '');
        $media = Http::get(config('instagram.base_url') . "/{$res['id']}", ['fields' => 'permalink', 'access_token' => $this->ig->token()])->json();

        return $this->update($id, ['status' => 'posted', 'media_id' => $res['id'], 'permalink' => $media['permalink'] ?? null]);
    }

    /* ---------- collage detection ---------- */

    /**
     * Splits a collage into panels: first into bands separated by full-width light rows,
     * then each band into columns separated by full-height light gutters.
     * Returns [[x, y, w, h], ...]; a plain image yields one panel covering it.
     */
    public function panels(string $file): array
    {
        $img = @imagecreatefromstring(file_get_contents($file));
        if (!$img) {
            return [null];
        }
        $w = imagesx($img);
        $h = imagesy($img);

        $rects = [];
        foreach ($this->runs($h, fn ($y) => $this->isLightLine($img, 0, $w, $y, true)) as [$y0, $y1]) {
            foreach ($this->runs($w, fn ($x) => $this->isLightLine($img, $y0, $y1, $x, false)) as [$x0, $x1]) {
                $rects[] = [$x0, $y0, $x1 - $x0, $y1 - $y0];
            }
        }
        imagedestroy($img);

        // Drop slivers; if nothing sensible was found, use the whole image.
        $rects = array_values(array_filter($rects, fn ($r) => $r[2] > $w * 0.12 && $r[3] > $h * 0.2));
        return $rects ?: [[0, 0, $w, $h]];
    }

    /** Content spans [start, end) between gutters (runs of ≥3 light lines). */
    private function runs(int $length, callable $isGutter): array
    {
        $spans = [];
        $start = null;
        $gap = 0;
        for ($i = 0; $i < $length; $i++) {
            if ($isGutter($i)) {
                $gap++;
                if ($gap === 3 && $start !== null) {
                    $spans[] = [$start, $i - 2];
                    $start = null;
                }
            } else {
                if ($start === null) {
                    $start = $i;
                }
                $gap = 0;
            }
        }
        if ($start !== null) {
            $spans[] = [$start, $length];
        }
        return $spans;
    }

    /** True when ~all sampled pixels along the line are near-white. */
    private function isLightLine(\GdImage $img, int $from, int $to, int $at, bool $horizontal): bool
    {
        $samples = 40;
        $light = 0;
        for ($k = 0; $k < $samples; $k++) {
            $p = (int) ($from + ($to - $from - 1) * $k / ($samples - 1));
            $rgb = $horizontal ? imagecolorat($img, $p, $at) : imagecolorat($img, $at, $p);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            if ($r > 238 && $g > 238 && $b > 238) {
                $light++;
            }
        }
        return $light >= $samples - 1;
    }

    private function ffmpeg(): string
    {
        foreach (explode(';', config('reels.path_extra')) as $dir) {
            if (is_file("{$dir}/ffmpeg.exe")) {
                return "{$dir}/ffmpeg.exe";
            }
        }
        return 'ffmpeg';
    }
}
