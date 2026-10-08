<?php

return [
    // Remotion project that holds pipeline/daily.ts and the DailyReel template
    'project_dir' => env('REEL_PROJECT_DIR', base_path('../remi-reel')),

    // Command that runs the pipeline (npx must be on PATH for the scheduler user)
    'npx' => env('REEL_NPX', 'C:\Program Files\nodejs\npx.cmd'),

    // Prepended to PATH for the pipeline: the scheduler runs as SYSTEM, which
    // doesn't see node/ffmpeg from the Administrator user's PATH.
    'path_extra' => env('REEL_PATH_EXTRA', 'C:\Program Files\nodejs;C:\Users\Administrator\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-8.1.2-full_build\bin'),

    // When the daily draft is generated (scheduler time, Asia/Kolkata)
    'daily_at' => env('REEL_DAILY_AT', '10:00'),

    // Uploaded reels are served from here: <public_url>/<date>.mp4
    'public_url' => rtrim(env('REEL_PUBLIC_URL', ''), '/'),

    // Telegram bot used to approve drafts from the phone (see app/Services/TelegramBot.php)
    'telegram_token' => env('TELEGRAM_BOT_TOKEN'),

    // Keys passed to the Node pipeline
    'env' => [
        'GEMINI_API_KEY'      => env('GEMINI_API_KEY2', env('GEMINI_API_KEY')), // KEY2 takes priority while set
        'GEMINI_MODEL'        => env('GEMINI_MODEL', 'gemini-3.8-flash'),
        'ELEVENLABS_API_KEY'  => env('ELEVENLABS_API_KEY'),
        'ELEVENLABS_VOICE_ID' => env('ELEVENLABS_VOICE_ID'),
        'ELEVENLABS_MODEL'    => env('ELEVENLABS_MODEL', 'eleven_multilingual_v2'),
        'REEL_HANDLE'         => env('REEL_HANDLE', '@sagar_khandaar'),
    ],
];
