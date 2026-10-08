<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Allows the request only from this machine. */
class LocalOnly
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);
        return $next($request);
    }
}
