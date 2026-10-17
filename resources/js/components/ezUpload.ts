/* item 35: shared upload helpers for the React editor panel.
   Same behaviour as WallCore.ezUploadUrl / ezImgDims / ezImgTag in wall-core.js
   (tests/js/item34-35.test.cjs checks the two copies agree). */
type Loc = { host?: string; protocol?: string } | null;
const devHost = (x: string) => /^(localhost|127\.|0\.0\.0\.0|\[::1\])/i.test(x || '');
const escA = (s: string) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');

/** Uploaded files are stored as https (or relative on a dev host), never http://. */
export function ezUploadUrl(url: string, loc?: Loc): string {
  url = String(url == null ? '' : url);
  const l: Loc = loc || (typeof location !== 'undefined' ? location : null);
  if (!url || /^(data|blob):/i.test(url)) return url;
  const host = (l && l.host) || '';
  if (url.charAt(0) === '/' && url.charAt(1) !== '/') {
    if (!host || !/^https?:$/.test((l && l.protocol) || '')) return url;
    return (devHost(host) ? (l as any).protocol + '//' : 'https://') + host + url;
  }
  if (url.slice(0, 2) === '//') return 'https:' + url;
  const m = /^http:\/\/([^\/?#]+)(.*)$/i.exec(url);
  if (m && !devHost(m[1])) return 'https://' + m[1] + m[2];
  return url;
}

/** Natural size of an image file (null if unknown within ms). */
export function ezImgDims(file: Blob, ms = 4000): Promise<{ w: number; h: number } | null> {
  return new Promise((res) => {
    let done = false;
    let u = '';
    const fin = (v: { w: number; h: number } | null) => {
      if (done) return;
      done = true;
      try { if (u) URL.revokeObjectURL(u); } catch { /* ignore */ }
      res(v);
    };
    try {
      const im = new Image();
      u = URL.createObjectURL(file);
      im.onload = () => fin(im.naturalWidth > 0 && im.naturalHeight > 0 ? { w: im.naturalWidth, h: im.naturalHeight } : null);
      im.onerror = () => fin(null);
      im.src = u;
    } catch { fin(null); }
    setTimeout(() => fin(null), ms);
  });
}

/** The image card block, with width/height so its space is reserved before it loads. */
export function ezImgTag(url: string, name: string, d?: { w: number; h: number } | null): string {
  const wh = d && d.w > 0 && d.h > 0 ? ` width="${Math.round(d.w)}" height="${Math.round(d.h)}"` : '';
  return `<img src="${escA(url)}" alt="${escA(name || '')}"${wh} style="max-width:100%;height:auto;display:block;border-radius:8px;margin:0 auto" />`;
}
