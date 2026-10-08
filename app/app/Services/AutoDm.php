<?php

namespace App\Services;

/**
 * Comment-to-DM automation ("Comment CODE and I'll DM you the guide").
 *
 * Rules and the list of already-handled comments live in storage/app/autodm.json:
 *   rules: [{ keyword, message, reply, media }]   media = "*" (all recent posts) or a permalink shortcode
 *   done:  { "<comment id>": "<ISO time>" }
 */
class AutoDm
{
    private string $file;

    public function __construct(private InstagramService $ig)
    {
        $this->file = storage_path('app/autodm.json');
    }

    public function state(): array
    {
        $s = is_file($this->file) ? json_decode(file_get_contents($this->file), true) : [];
        return ($s ?: []) + ['rules' => [], 'done' => [], 'username' => null];
    }

    private function save(array $s): void
    {
        file_put_contents($this->file, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function setRule(string $keyword, string $message, string $reply = '', string $media = '*'): void
    {
        $s = $this->state();
        $s['rules'] = array_values(array_filter($s['rules'], fn ($r) => strcasecmp($r['keyword'], $keyword) !== 0));
        $s['rules'][] = compact('keyword', 'message', 'reply', 'media');
        $this->save($s);
    }

    public function removeRule(string $keyword): void
    {
        $s = $this->state();
        $s['rules'] = array_values(array_filter($s['rules'], fn ($r) => strcasecmp($r['keyword'], $keyword) !== 0));
        $this->save($s);
    }

    /**
     * Checks comments on recent posts and DMs everyone who used a keyword.
     * Returns a list of [username, keyword, ok, error] for the comments handled this run.
     */
    public function run(int $recentPosts = 10): array
    {
        $s = $this->state();
        if (!$s['rules']) {
            return [];
        }
        $s['username'] ??= $this->ig->profile()['username'] ?? null;

        // On the very first run, mark existing comments as seen so old commenters aren't messaged.
        $firstRun = !isset($s['initialised']);
        $handled = [];

        foreach ($this->ig->recentMedia($recentPosts) as $media) {
            $shortcode = basename(rtrim($media['permalink'] ?? '', '/'));
            $rules = array_filter($s['rules'], fn ($r) => $r['media'] === '*' || $r['media'] === $shortcode);
            if (!$rules) {
                continue;
            }
            foreach ($this->ig->comments($media['id']) as $c) {
                if (isset($s['done'][$c['id']]) || ($c['username'] ?? null) === $s['username']) {
                    continue;
                }
                if ($firstRun) {
                    $s['done'][$c['id']] = 'skipped-initial';
                    continue;
                }
                foreach ($rules as $r) {
                    if (!preg_match('/(^|\W)' . preg_quote($r['keyword'], '/') . '($|\W)/iu', $c['text'] ?? '')) {
                        continue;
                    }
                    try {
                        $this->ig->privateReply($c['id'], $r['message']);
                        if ($r['reply'] !== '') {
                            rescue(fn () => $this->ig->replyToComment($c['id'], '@' . $c['username'] . ' ' . $r['reply']), null, false);
                        }
                        $handled[] = [$c['username'] ?? '?', $r['keyword'], true, null];
                    } catch (\Throwable $e) {
                        $handled[] = [$c['username'] ?? '?', $r['keyword'], false, $this->clean($e->getMessage())];
                    }
                    break;
                }
                $s['done'][$c['id']] = now()->toIso8601String();
            }
        }

        $s['initialised'] = true;
        // Instagram only allows private replies within 7 days, so older ids can be forgotten.
        $s['done'] = array_slice($s['done'], -5000, null, true);
        $this->save($s);
        return $handled;
    }

    private function clean(string $msg): string
    {
        return mb_substr(preg_replace('/access_token=[^&\s"]+/', 'access_token=***', $msg), 0, 300);
    }
}
