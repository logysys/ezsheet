<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><meta name="referrer" content="no-referrer"><title>Signing in…</title>
<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:420px;margin:20vh auto;padding:0 20px;color:#111;text-align:center}button{font:inherit;padding:8px 18px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;cursor:pointer}</style>
</head><body>
<form id="f" method="POST" action="/auth/handoff/claim">
<input type="hidden" name="_token" value="{{ $csrf }}">
<input type="hidden" name="t" value="{{ $t }}">
<input type="hidden" name="r" value="{{ $r }}">
<p>Signing you in…</p>
<noscript><button type="submit">Continue</button></noscript>
</form>
<script>
try{history.replaceState(null,'','/auth/handoff/claim');}catch(e){}
document.getElementById('f').submit();
</script>
</body></html>
