<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class InstagramService
{
    private string $base;
    private string $userId;

    public function __construct()
    {
        $this->base   = rtrim(config('instagram.base_url'), '/');
        $this->userId = (string) config('instagram.user_id');
    }

    /* ---------- token handling ---------- */

    public function token(): string
    {
        $file = config('instagram.token_file');
        if (is_file($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (!empty($data['access_token'])) {
                return $data['access_token'];
            }
        }
        $token = config('instagram.access_token');
        if (!$token) {
            throw new RuntimeException('IG_ACCESS_TOKEN missing in .env');
        }
        return $token;
    }

    /** Long-lived tokens last 60 days; refresh at least once before expiry. */
    public function refreshToken(): array
    {
        $res = Http::get('https://graph.instagram.com/refresh_access_token', [
            'grant_type'   => 'ig_refresh_token',
            'access_token' => $this->token(),
        ])->throw()->json();

        file_put_contents(config('instagram.token_file'), json_encode([
            'access_token' => $res['access_token'],
            'expires_in'   => $res['expires_in'] ?? null,
            'refreshed_at' => now()->toIso8601String(),
        ]));

        return $res;
    }

    /* ---------- publishing ---------- */

    public function postPhoto(string $imageUrl, string $caption = ''): array
    {
        $id = $this->createContainer([
            'image_url' => $imageUrl,
            'caption'   => $caption,
        ]);
        return $this->publish($id);
    }

    public function postReel(string $videoUrl, string $caption = '', ?string $coverUrl = null, bool $shareToFeed = true): array
    {
        $params = [
            'media_type'    => 'REELS',
            'video_url'     => $videoUrl,
            'caption'       => $caption,
            'share_to_feed' => $shareToFeed ? 'true' : 'false',
        ];
        if ($coverUrl) {
            $params['cover_url'] = $coverUrl;
        }
        $id = $this->createContainer($params);
        $this->waitUntilReady($id);
        return $this->publish($id);
    }

    /** $items = [['type'=>'image','url'=>...], ['type'=>'video','url'=>...]] (2-10 items) */
    public function postCarousel(array $items, string $caption = ''): array
    {
        $children = [];
        foreach ($items as $item) {
            $p = ['is_carousel_item' => 'true'];
            if (($item['type'] ?? 'image') === 'video') {
                $p['media_type'] = 'VIDEO';
                $p['video_url']  = $item['url'];
            } else {
                $p['image_url'] = $item['url'];
            }
            $cid = $this->createContainer($p);
            if (($item['type'] ?? 'image') === 'video') {
                $this->waitUntilReady($cid);
            }
            $children[] = $cid;
        }

        $id = $this->createContainer([
            'media_type' => 'CAROUSEL',
            'children'   => implode(',', $children),
            'caption'    => $caption,
        ]);
        $this->waitUntilReady($id);
        return $this->publish($id);
    }

    /* ---------- comments ---------- */

    public function comments(string $mediaId): array
    {
        return $this->get("/{$mediaId}/comments", ['fields' => 'id,text,username,timestamp'])['data'] ?? [];
    }

    public function replyToComment(string $commentId, string $message): array
    {
        return $this->post("/{$commentId}/replies", ['message' => $message]);
    }

    public function recentMedia(int $limit = 10): array
    {
        return $this->get("/{$this->userId}/media", [
            'fields' => 'id,caption,media_type,permalink,timestamp',
            'limit'  => $limit,
        ])['data'] ?? [];
    }

    public function publishingLimit(): array
    {
        return $this->get("/{$this->userId}/content_publishing_limit", ['fields' => 'quota_usage,config']);
    }

    /* ---------- internals ---------- */

    private function createContainer(array $params): string
    {
        return $this->post("/{$this->userId}/media", $params)['id'];
    }

    private function publish(string $creationId): array
    {
        return $this->post("/{$this->userId}/media_publish", ['creation_id' => $creationId]);
    }

    private function waitUntilReady(string $containerId): void
    {
        for ($i = 0; $i < config('instagram.poll_max'); $i++) {
            $status = $this->get("/{$containerId}", ['fields' => 'status_code,status'])['status_code'] ?? '';
            if ($status === 'FINISHED') {
                return;
            }
            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                throw new RuntimeException("Instagram container {$containerId} failed: {$status}");
            }
            sleep((int) config('instagram.poll_seconds'));
        }
        throw new RuntimeException("Instagram container {$containerId} timed out");
    }

    private function get(string $path, array $query = []): array
    {
        return Http::get($this->base . $path, $query + ['access_token' => $this->token()])
            ->throw()->json();
    }

    private function post(string $path, array $data = []): array
    {
        return Http::asForm()
            ->post($this->base . $path, $data + ['access_token' => $this->token()])
            ->throw()->json();
    }
}
