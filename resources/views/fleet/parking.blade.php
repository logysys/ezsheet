<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>{{ $title ?? 'Not found' }}</title>
<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:480px;margin:15vh auto;padding:0 20px;color:#111}h1{font-size:20px}a{color:#2563eb}</style>
</head><body>
<h1>{{ $title ?? 'This site is not active' }}</h1>
<p>{{ $text ?? 'There is nothing published at this address yet.' }}</p>
@if(!empty($host))<p style="color:#64748b;font-size:13px">{{ $host }}</p>@endif
<p><a href="https://{{ config('fleet.flagship') }}/">{{ config('fleet.flagship') }}</a></p>
</body></html>
