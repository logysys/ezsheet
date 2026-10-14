/* Item 14: a wall opened on its custom subdomain (e.g. https://3619.ez.wiki) cannot see the
   owner's ez.wiki login, because the session cookie is host-only on ez.wiki (SESSION_DOMAIN
   unset) and we deliberately keep it that way (see the release README). Instead the page offers
   "Edit on ez.wiki", a plain link to the wall on the main host that opens the EzSheet editor
   when the visitor there is the owner. No cookie, CORS or write-guard change is needed. */

/** Main-site URL that opens the editor for this wall, or null when already on the main host. */
export function ownerEditLink(hostname, slug, protocol = 'https:') {
  const h = String(hostname || '').toLowerCase().replace(/\.$/, '');
  const s = String(slug || '').trim();
  if (!h || !s || s === 'ezsheet') return null;
  if (h === 'localhost' || /^[\d.]+$/.test(h) || h.includes(':')) return null;
  const labels = h.split('.');
  if (labels.length < 3 || labels[0] === 'www') return null;
  const root = labels.slice(1).join('.');
  return (protocol === 'http:' ? 'http:' : 'https:') + '//' + root + '/' + encodeURIComponent(s) + '?ezsheet=edit';
}

/** True when the URL asks to open the EzSheet editor (?ezsheet=edit). */
export function wantsEditorOpen(search) {
  try { return new URLSearchParams(String(search || '')).get('ezsheet') === 'edit'; } catch (e) { return false; }
}
