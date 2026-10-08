<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Daily Reels</title>
<style>
  :root { --bg:#0b1020; --card:#131a2e; --ink:#e2e8f0; --muted:#94a3b8; --ok:#22c55e; --bad:#ef4444; --accent:#22d3ee; }
  body { margin:0; background:var(--bg); color:var(--ink); font:16px/1.5 system-ui, sans-serif; }
  main { max-width:960px; margin:0 auto; padding:24px 16px; }
  .card { display:flex; gap:20px; background:var(--card); border-radius:16px; padding:16px; margin-bottom:16px; flex-wrap:wrap; }
  video { width:220px; border-radius:12px; background:#000; }
  .meta { flex:1; min-width:260px; }
  .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:13px; background:#1e293b; }
  .pending { color:var(--accent); } .posted { color:var(--ok); } .failed,.rejected { color:var(--bad); }
  pre { white-space:pre-wrap; background:#0f172a; padding:10px; border-radius:8px; font-size:14px; }
  button { border:0; border-radius:10px; padding:10px 18px; font-weight:600; cursor:pointer; margin-right:8px; }
  .approve { background:var(--ok); color:#04130a; } .reject { background:#334155; color:var(--ink); }
  a { color:var(--accent); } .flash { background:#1e293b; padding:10px 14px; border-radius:10px; margin-bottom:16px; }
  details { color:var(--muted); font-size:14px; }
</style>
</head>
<body><main>
<h1>Daily Reels</h1>
@if (session('msg'))<div class="flash">{{ session('msg') }}</div>@endif
@forelse ($drafts as $d)
  <div class="card">
    @if (in_array($d['status'], ['pending', 'posted', 'rejected']))
      <video src="{{ url('reels/'.$d['date'].'/video') }}" controls preload="metadata"></video>
    @endif
    <div class="meta">
      <div><strong>{{ $d['date'] }}</strong> · {{ $d['type'] ?? '' }} · <span class="badge {{ $d['status'] }}">{{ $d['status'] }}</span></div>
      <h3 style="margin:8px 0">{{ $d['topic'] ?? '' }}</h3>
      @if (!empty($d['error']))<pre>{{ $d['error'] }}</pre>@endif
      @if (!empty($d['caption']))<pre>{{ $d['caption'] }}</pre>@endif
      @if (!empty($d['sources']))
        <details><summary>Sources ({{ count($d['sources']) }})</summary>
          @foreach ($d['sources'] as $s)<div><a href="{{ $s }}" target="_blank" rel="noopener">{{ $s }}</a></div>@endforeach
        </details>
      @endif
      @if (!empty($d['voice']))<details><summary>Voice-over script</summary><p>{{ $d['voice'] }}</p></details>@endif
      @if (!empty($d['permalink']))<p><a href="{{ $d['permalink'] }}" target="_blank">Open on Instagram</a></p>@endif
      @if ($d['status'] === 'pending')
        <form method="post" action="{{ url('reels/'.$d['date'].'/approve') }}" style="display:inline" onsubmit="this.querySelector('button').textContent='Posting…'">@csrf<button class="approve">Approve &amp; post</button></form>
        <form method="post" action="{{ url('reels/'.$d['date'].'/reject') }}" style="display:inline">@csrf<button class="reject">Reject</button></form>
      @endif
    </div>
  </div>
@empty
  <p>No drafts yet. Run <code>php artisan reel:daily</code>.</p>
@endforelse
</main></body>
</html>
