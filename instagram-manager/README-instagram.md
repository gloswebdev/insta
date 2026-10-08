# Instagram service (Laravel)

## Install
Copy into your Laravel project, keeping the same paths:

- `config/instagram.php`
- `app/Services/InstagramService.php`
- `app/Console/Commands/InstagramRefreshToken.php`
- `app/Console/Commands/InstagramPost.php`

## .env
```
IG_USER_ID=17841403845475746
IG_ACCESS_TOKEN=<token from Meta dashboard "Generate token">
```
Never commit `.env`. The token generated in the dashboard is already long-lived (about 60 days).

## Keep the token alive
Add to `routes/console.php` (Laravel 11+) or `app/Console/Kernel.php`:
```php
Schedule::command('instagram:refresh-token')->weekly();
```
The refreshed token is saved to `storage/app/instagram_token.json` and takes priority over `.env`.

## Test
```
php artisan instagram:post photo "https://your-host/test.jpg" --caption="test"
php artisan instagram:post reel  "https://your-host/test.mp4" --caption="test reel"
```

## Use in code
```php
app(\App\Services\InstagramService::class)->postReel($videoUrl, $caption);
```

## Rules to remember
- Image/video must be a public HTTPS URL that Instagram can fetch.
- Reels: MP4 (H.264/AAC), 9:16, up to 15 min via API.
- Limit: about 100 API-published posts per 24 hours (`publishingLimit()` shows usage).
- The app is in dev mode: works only for accounts with a role on the app (your own).
