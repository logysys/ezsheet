/* item 28 (EZ-REL-20261016-6): what the owner sees on the PARENT page after "Push to wall".
   Pure functions (no DOM, no network) so they can be unit-tested. Used by AISearchView. */
export const EZ_PUSH_TIMEOUT_MS = 30000;

const M = {
  en: {
    saved: 'Saved',
    cards: '{n} WiKi cards',
    zones: 'Topic {a} · Content {b} · InfoMercial {c}',
    created: '+{n} new', updated: '{n} changed', deleted: '{n} removed',
    notAdded: '{n} not added (the same link is already a Co-WiKi card)',
    owner: 'Only the wall owner can push to this wall. Are you signed in with the account that owns it?',
    signedOut: "You're signed out on this site. Your session may have ended because you signed out somewhere else. Sign in again, then push.",
    notOwner: 'This wall belongs to another account. Sign in with the account that owns it.',
    notOwnerAs: "This wall belongs to another account. You're signed in as {email}. Sign in with the account that owns it.",
    crossOrigin: "Push blocked: this page's address doesn't match the wall's site. Reload the page and try again.",
    clientRefused: "This page doesn't recognise you as the owner, so nothing was sent. Reload the page; if it still happens, sign in with the owner's account.",
    expired: 'Your session expired. Please refresh the page and push again.',
    problems: 'Nothing was saved:',
    locked: 'WiKi cards with Co-WiKi cards must stay in the Content zone: {list}',
    empty: 'The sheet is empty.',
    busy: 'A push is already running. Please wait.',
    timeout: "Server didn't answer. Nothing may have been saved. Please try again.",
    notFound: 'This wall was not found. Please refresh the page.',
    tooBig: 'The sheet is too large for the server.',
    server: 'Server error ({s}). Nothing may have been saved. Please try again.',
    failed: 'Push failed ({s}).',
  },
  'zh-TW': {
    saved: '已儲存',
    cards: '{n} 張 WiKi 卡',
    zones: '主題 {a} · 內容 {b} · 資訊廣告 {c}',
    created: '新增 {n}', updated: '修改 {n}', deleted: '移除 {n}',
    notAdded: '{n} 張沒有加入（同樣的連結已是 Co-WiKi 卡）',
    owner: '只有牆的擁有者可以推送。請確認登入的是擁有這面牆的帳號。',
    signedOut: '你在這個網站已經登出（可能是在別處登出了）。請重新登入後再推送。',
    notOwner: '這面牆屬於另一個帳號。請用擁有這面牆的帳號登入。',
    notOwnerAs: '這面牆屬於另一個帳號。你目前登入的是 {email}。請用擁有這面牆的帳號登入。',
    crossOrigin: '推送被擋下：這個頁面的網址和牆的網站不一致。請重新整理頁面後再試一次。',
    clientRefused: '這個頁面沒有把你認作擁有者，所以沒有送出任何內容。請重新整理頁面；若仍發生，請用擁有者的帳號登入。',
    expired: '登入已逾時，請重新整理頁面後再推送一次。',
    problems: '沒有儲存任何內容：',
    locked: '有 Co-WiKi 卡的 WiKi 卡必須留在內容區：{list}',
    empty: 'EzSheet 是空的。',
    busy: '正在推送中，請稍候。',
    timeout: '伺服器沒有回應，可能沒有儲存。請再試一次。',
    notFound: '找不到這面牆，請重新整理頁面。',
    tooBig: 'EzSheet 內容太大，伺服器無法接收。',
    server: '伺服器錯誤（{s}），可能沒有儲存。請再試一次。',
    failed: '推送失敗（{s}）。',
  },
  'zh-CN': {
    saved: '已保存',
    cards: '{n} 张 WiKi 卡',
    zones: '主题 {a} · 内容 {b} · 信息广告 {c}',
    created: '新增 {n}', updated: '修改 {n}', deleted: '移除 {n}',
    notAdded: '{n} 张没有加入（同样的链接已是 Co-WiKi 卡）',
    owner: '只有墙的拥有者可以推送。请确认登录的是拥有这面墙的账号。',
    signedOut: '你在这个网站已经退出登录（可能是在别处退出了）。请重新登录后再推送。',
    notOwner: '这面墙属于另一个账号。请用拥有这面墙的账号登录。',
    notOwnerAs: '这面墙属于另一个账号。你当前登录的是 {email}。请用拥有这面墙的账号登录。',
    crossOrigin: '推送被拦下：这个页面的网址和墙的网站不一致。请刷新页面后再试一次。',
    clientRefused: '这个页面没有把你识别为拥有者，所以没有发送任何内容。请刷新页面；如果仍然发生，请用拥有者的账号登录。',
    expired: '登录已过期，请刷新页面后再推送一次。',
    problems: '没有保存任何内容：',
    locked: '有 Co-WiKi 卡的 WiKi 卡必须留在内容区：{list}',
    empty: 'EzSheet 是空的。',
    busy: '正在推送中，请稍候。',
    timeout: '服务器没有响应，可能没有保存。请再试一次。',
    notFound: '找不到这面墙，请刷新页面。',
    tooBig: 'EzSheet 内容太大，服务器无法接收。',
    server: '服务器错误（{s}），可能没有保存。请再试一次。',
    failed: '推送失败（{s}）。',
  },
};

/** item 36: the texts, shared with the EzSheet editor frame (EzSheetPreview injects them). */
export const EZ_PUSH_TEXTS = M;

/** item 36 (EZ-REL-20261017-3): the refusal text for a push, or '' when it isn't a refusal.
 *  SELF-CONTAINED on purpose (no outside names): EzSheetPreview injects ezPushRefusalText.toString()
 *  into the editor frame, so the frame toast and this page show the SAME words.
 *  401 / signed_out, 403 cross_origin, 403 not_owner (+ signed_in_as), client_refused (nothing was sent),
 *  any other 403 (old server) -> the old owner text. 419 is handled separately and is unchanged. */
export function ezPushRefusalText(status, data, lang, texts) {
  var d = data && typeof data === 'object' ? data : {};
  var tx = texts && texts.en ? texts : { en: {} };
  var s = String(lang || '').replace('_', '-').toLowerCase();
  var L = /^zh-(tw|hk|mo|hant)/.test(s) ? 'zh-TW' : /^zh/.test(s) ? 'zh-CN' : 'en';
  var get = function (k, v) {
    var t = (tx[L] && tx[L][k]) || tx.en[k] || k;
    return v ? t.replace(/\{(\w+)\}/g, function (_, x) { return v[x] != null ? String(v[x]) : ''; }) : t;
  };
  if (d.error === 'client_refused' || d.error === 'forbidden') return get('clientRefused');
  if (status === 401 || d.error === 'signed_out') return get('signedOut');
  if (status !== 403) return '';
  if (d.error === 'cross_origin') return get('crossOrigin');
  if (d.error === 'not_owner') return d.signed_in_as ? get('notOwnerAs', { email: d.signed_in_as }) : get('notOwner');
  return get('owner');
}

/** 'zh-TW' | 'zh-CN' | 'en' from any language tag (zh-Hant, zh_TW, zh-HK -> zh-TW; zh, zh-Hans -> zh-CN). */
export function ezPushLang(l) {
  const s = String(l || '').replace('_', '-').toLowerCase();
  if (/^zh-(tw|hk|mo|hant)/.test(s)) return 'zh-TW';
  if (/^zh/.test(s)) return 'zh-CN';
  return 'en';
}

function fmt(lang, k, v) {
  let s = (M[lang] && M[lang][k]) || M.en[k] || k;
  return v ? s.replace(/\{(\w+)\}/g, (_, x) => (v[x] != null ? String(v[x]) : '')) : s;
}

/** An axios (or fetch) error -> { status, data }. Abort / timeout / no response -> status 0, error 'timeout'. */
export function ezPushErrorStatus(err) {
  const r = err && err.response;
  if (r && typeof r.status === 'number' && r.status > 0) return { status: r.status, data: r.data && typeof r.data === 'object' ? r.data : {} };
  return { status: 0, data: { error: 'timeout' } };
}

/** The message for the parent page. Returns { ok, text, lines }. */
export function ezPushFeedback(status, data, lang) {
  lang = ezPushLang(lang);
  const d = data && typeof data === 'object' ? data : {};
  const T = (k, v) => fmt(lang, k, v);
  if (status >= 200 && status < 300 && Array.isArray(d.cards)) {
    const z = { a: 0, b: 0, c: 0 };
    d.cards.forEach((c) => { if (c && c.role !== 'child' && !c.mother_id) z[c.zone === 'a' || c.zone === 'c' ? c.zone : 'b']++; });
    const n = z.a + z.b + z.c, st = d.stats || {};
    const ch = [];
    if (st.created) ch.push(T('created', { n: st.created }));
    if (st.updated) ch.push(T('updated', { n: st.updated }));
    if (st.deleted) ch.push(T('deleted', { n: st.deleted }));
    const lines = [T('zones', z)];
    const na = Array.isArray(d.not_added) ? d.not_added.length : 0;
    if (na) lines.push(T('notAdded', { n: na }));
    return { ok: true, text: '✓ ' + T('saved') + ': ' + T('cards', { n }) + (ch.length ? ' (' + ch.join(', ') + ')' : ''), lines };
  }
  if (status === 0 || d.error === 'timeout' || d.error === 'network') return { ok: false, text: T('timeout'), lines: [] };
  if (d.error === 'busy') return { ok: false, text: T('busy'), lines: [] };
  const refused = ezPushRefusalText(status, d, lang, M);   // item 36: one text per refusal reason
  if (refused) return { ok: false, text: refused, lines: [] };
  if (status === 419) return { ok: false, text: T('expired'), lines: [] };
  if (status === 404) return { ok: false, text: T('notFound'), lines: [] };
  if (status === 413) return { ok: false, text: T('tooBig'), lines: [] };
  if (status === 422) {
    const lines = [];
    if (d.error === 'mother_zone_locked') {
      lines.push(T('locked', { list: (d.cards || []).map((c) => String(c.raw || '').slice(0, 60)).join(' / ') }));
    } else if (d.error === 'empty_sheet') {
      lines.push(T('empty'));
    } else if (d.errors && typeof d.errors === 'object') {
      Object.keys(d.errors).forEach((k) => [].concat(d.errors[k]).forEach((m) => lines.push(String(m))));
    }
    if (!lines.length && d.message) lines.push(String(d.message));
    return { ok: false, text: T('problems'), lines };
  }
  if (status >= 500) return { ok: false, text: T('server', { s: status }), lines: [] };
  return { ok: false, text: T('failed', { s: status }) + (d.message ? ' ' + String(d.message) : ''), lines: [] };
}
