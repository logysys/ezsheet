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
  if (status === 401 || status === 403) return { ok: false, text: T('owner'), lines: [] };
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
