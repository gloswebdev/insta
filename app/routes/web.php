<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 | Daily reel review page — only reachable from this PC.
 */
Route::prefix('reels')->middleware(App\Http\Middleware\LocalOnly::class)->group(function () {
    Route::get('/', fn (App\Services\ReelPipeline $p) => view('reels.index', ['drafts' => $p->drafts()]));

    Route::get('{date}/video', function (string $date, App\Services\ReelPipeline $p) {
        $file = $p->manifest($date)['file'] ?? null;
        abort_unless($file && is_file($file), 404);
        return response()->file($file, ['Content-Type' => 'video/mp4']);
    })->where('date', '\d{4}-\d{2}-\d{2}');

    Route::post('{date}/{action}', function (string $date, string $action, App\Services\ReelPipeline $p) {
        set_time_limit(0);
        try {
            $action === 'approve' ? $p->publish($date) : $p->reject($date);
            $msg = $action === 'approve' ? "Posted {$date}" : "Rejected {$date}";
        } catch (Throwable $e) {
            $msg = 'Error: ' . $e->getMessage();
        }
        return redirect('reels')->with('msg', $msg);
    })->where(['date' => '\d{4}-\d{2}-\d{2}', 'action' => 'approve|reject']);
});
