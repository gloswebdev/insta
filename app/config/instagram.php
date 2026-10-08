<?php

return [
    // Instagram API with Instagram Login (graph.instagram.com)
    'base_url'     => env('IG_BASE_URL', 'https://graph.instagram.com/v21.0'),
    'user_id'      => env('IG_USER_ID'),
    'access_token' => env('IG_ACCESS_TOKEN'),

    // Where the refreshed token is stored (the .env value is only the starting token)
    'token_file'   => storage_path('app/instagram_token.json'),

    // Container processing wait (videos/reels need time before publish)
    'poll_seconds' => 5,
    'poll_max'     => 60,
];
