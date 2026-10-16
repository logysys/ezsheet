import React, { useEffect, useRef, useState, useMemo } from 'react';
import WALL_CORE_SCRIPT from './wall-core.js?raw';
import { EZSHEET_EDITOR_SCRIPT, getEzSheetEditor } from './ezsheetEditor';
import { wallAutoHeightReporter, createWallHeightDamper } from './ezAutoHeight.mjs';
import ZDOG_SCRIPT from 'zdog/dist/zdog.dist.min.js?raw';

/* ============================================================
 * EzSheetPreview.tsx — full-featured wall preview
 * Mirrors wall-editor-v620.01.html: all zones, 3D, Markdown,
 * effects, sync, resize, insert, filter, i18n (6 languages).
 * ============================================================ */

interface EzSheetItem {
  _raw?: string;
  _id?: string;
  type?: 'youtube' | 'html' | 'web' | 'model' | 'md';
  url?: string;
  id?: string;
  html?: string;
  md?: string;
  mdl?: { d?: string; u?: string; e?: string; n?: string };
  w?: number; h?: number; mh?: number;
  title?: string;
  tags?: string[] | string;
  zone?: 'a' | 'b' | 'c';
  at?: number; pub?: number; dur?: number;
  span?: number; rh?: number;
  emb?: 'yes' | 'no';
  blk?: boolean;
  manual?: boolean;
  free?: boolean;
  px?: number; py?: number;
  pic?: boolean;
}

interface EzSheetPreviewProps {
  cards?: any[];
  content?: string;
  title?: string;
  className?: string;
  height?: number;
  /** item 10: grow the iframe to the wall document's full height (damped height messages from the wall frame). */
  autoHeight?: boolean;
  /** item 10: no border, radius or shadow; transparent background. */
  frameless?: boolean;
  /** item 10: lower bound for autoHeight (px). */
  minHeight?: number;
  /** item 14: open the EzSheet editor once the wall is ready, if the viewer is the owner (?ezsheet=edit). */
  openEditor?: boolean;
  wallId?: string;
  lang?: string;
  cols?: number;
  car?: boolean;
  filter?: boolean;
  editable?: boolean;
  zones?: { a?: boolean; b?: boolean; c?: boolean };
  zorder?: ('a' | 'b' | 'c')[];
  flying?: boolean;
  sound?: boolean;
  sprites?: string[];
  syncUrl?: string;
  frameCheckUrl?: string;
  metaUrl?: string;
  auth?: { user?: any };
  isOwner?: boolean;
  slug?: string;
  funnel?: string;
  messageId?: number;
  domains?: Array<{ domain?: string } | string>;
  onItemsChange?: (items: EzSheetItem[]) => void;
  /** Owner push. `raw` is the cid-tagged sheet text. Call `reply` with the HTTP status and JSON body. */
  onPushToWall?: (data: { raw: string; deleteCids?: string[]; confirmEmpty?: boolean; requestId?: string; wallId?: string; slug?: string; messageId?: number }, reply: (status: number, body: any) => void) => void;
  /** Server rev of `cards` (from getCards / push / SSE). Snapshots with a lower rev are ignored. */
  rev?: number;
}

/* ============================================================
 * WALL CSS — from wall-editor-v620.01.html
 * ============================================================ */
const WALL_CSS = `
:root{--cloud:#F7F5F1;--teal:#0E7C7B;--green:#0A7A34;--yellow:#FFE27A;--ink:#111}
*{box-sizing:border-box}
html{scrollbar-gutter:stable}
body{margin:0;padding:16px;background:var(--cloud);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans",sans-serif;display:flex;flex-direction:column}
html:lang(zh-TW) body{font-family:-apple-system,"PingFang TC","Microsoft JhengHei","Noto Sans TC",sans-serif}
html:lang(zh-CN) body{font-family:-apple-system,"PingFang SC","Microsoft YaHei","Noto Sans SC",sans-serif}
html:lang(ja) body{font-family:-apple-system,"Hiragino Sans","Yu Gothic UI","Noto Sans JP",sans-serif}
html:lang(ko) body{font-family:-apple-system,"Apple SD Gothic Neo","Malgun Gothic","Noto Sans KR",sans-serif}
html:lang(th) body{font-family:-apple-system,"Thonburi","Leelawadee UI","Noto Sans Thai",sans-serif}
.masonry{position:relative;z-index:0;min-height:40px}
.zone-c{margin:0 auto 14px}
#wall[hidden]{display:none}
.pill.zord .lbl{letter-spacing:.08em}
.pill.zone{min-width:34px;justify-content:center;gap:4px}
@media (max-width:620px){.pill.zone .lbl{font-size:10px}}
.zn{font-size:9px;opacity:.75;font-weight:900}
.pill.zone[aria-pressed="false"]{opacity:.5}
.pill.zone[aria-pressed="true"]{background:var(--ink);color:#fff}
.masonry>.item{position:absolute;left:0;top:0}
.masonry.ready>.item{transition:left .4s cubic-bezier(.2,.8,.2,1),top .4s cubic-bezier(.2,.8,.2,1),width .4s cubic-bezier(.2,.8,.2,1),height .4s cubic-bezier(.2,.8,.2,1)}
body.resizing .masonry>.item,body.shuffling .masonry>.item{transition:none}
.masonry>.item.dragging{z-index:5}
@media (prefers-reduced-motion:reduce){.masonry.ready>.item{transition:none}}
.item{max-width:100%;border-radius:18px;overflow:hidden;background:#111;border:2.5px solid var(--ink);position:relative;box-shadow:0 10px 24px rgba(0,0,0,.14)}
.item iframe{width:100%;height:100%;border:0;display:block;background:#fff}
.item iframe.embed{background:transparent}
.ins,.gdel{width:22px;height:22px;padding:0;border-radius:50%;
  border:1.5px solid var(--ink);cursor:pointer;z-index:9;font-weight:900;font-size:13px;
  line-height:19px;text-align:center;opacity:.42;transition:opacity .15s,transform .15s}
.ins{background:#16A34A;color:#fff}
.gdel{background:#fff;color:var(--ink);font-size:13px}
.item:hover .ins,.item:hover .gdel,.ins:focus-visible,.gdel:focus-visible{opacity:1}
.ins:hover,.gdel:hover{transform:scale(1.12)}
.gdel:hover{background:#fee2e2;color:#ef4444;border-color:#ef4444}
@media (pointer:coarse){.ins,.gdel{opacity:.62}}
@media (prefers-reduced-motion:reduce){.ins,.gdel{transition:none}}
.pill[hidden],.pill.sync[hidden],.pill.rev[hidden]{display:none}
.pill.sync{padding:7px 9px;cursor:default;border-color:var(--ink)}
.pill.sync .mi{font-size:10px;width:auto}
.pill.sync.on .mi{color:var(--green)}
.pill.sync.off .mi{color:#c0392b}
.pill.rev{padding:7px 10px}
.rev-card{width:min(760px,100%)}
.rev-topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:6px}
.rev-title-group{display:flex;flex-direction:column;gap:3px}
.rev-cnt-pill{display:inline-block;font-size:11px;font-weight:800;background:#fef3c7;color:#b45309;padding:3px 10px;border-radius:999px;border:1.5px solid #f59e0b;white-space:nowrap}
.rev-list{max-height:55vh;overflow:auto;display:flex;flex-direction:column;gap:8px;
  border:2px solid var(--ink);border-radius:12px;padding:10px;background:var(--cloud)}
.rev-row{position:relative;background:#fff;border:1.5px solid rgba(0,0,0,.15);border-radius:10px;padding:10px 12px;display:flex;flex-direction:column;gap:6px;transition:all .15s ease}
.rev-row.pending{border-left:4px solid #f59e0b;background:#fffdfa}
.rev-row.ok{border-left:4px solid var(--teal);background:#fafffd}
.rev-meta{display:flex;gap:8px;align-items:center;font-size:11px;font-weight:700;color:var(--ink);flex-wrap:wrap}
.rev-st{font-size:10px;font-weight:900;padding:2px 8px;border-radius:99px;text-transform:uppercase;letter-spacing:.5px}
.rev-st-pend{background:#E7F8EC;color:#15803D;border:1.5px dotted #8FD9A8}
.rev-st-ok{background:var(--teal);color:#fff;border:1.5px solid var(--teal)}
.rev-time{opacity:.8;font-size:10.5px}
.rev-zone{background:var(--ink);color:#fff;border-radius:999px;padding:1px 8px;font-size:10px}
.rev-fp{background:#e2e8f0;color:#334155;border-radius:6px;padding:1px 6px;font-family:ui-monospace,Menlo,monospace;font-size:10.5px}
.rev-body{margin:2px 0}
.rev-raw{display:block;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;line-height:1.45;word-break:break-all;white-space:pre-wrap;max-height:72px;overflow:auto;background:#f8fafc;padding:6px 8px;border-radius:6px;border:1px solid #e2e8f0}
.rev-acts{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:2px}
.rev-ok{display:inline-flex;align-items:center;gap:4px;border-radius:999px;border:1.5px solid var(--teal);background:var(--teal);color:#fff;font-weight:900;cursor:pointer;padding:4px 12px;font-size:11px;transition:transform .1s ease}
.rev-ok:hover{background:#0d7a71;transform:scale(1.02)}
.rev-ok:disabled{opacity:.4;cursor:default}
.rev-x{display:inline-flex;align-items:center;gap:4px;border-radius:999px;border:1.5px solid #ef4444;background:#fff;color:#ef4444;font-weight:900;cursor:pointer;padding:4px 12px;font-size:11px;transition:transform .1s ease}
.rev-x:hover{background:#fef2f2;transform:scale(1.02)}
.rev-x:disabled{opacity:.4;cursor:default}
.rev-permanent-tag{font-size:10.5px;font-weight:800;color:var(--teal);padding:3px 8px;background:#ecfdf5;border-radius:999px;border:1px solid #a7f3d0}
.rev-none{font-size:12px;font-weight:800;opacity:.65;padding:16px;text-align:center}
.ins-modal{position:fixed;inset:0;z-index:120;background:rgba(17,17,17,.45);
  display:flex;align-items:center;justify-content:center;padding:18px}
.ins-card{width:min(560px,100%);background:#fff;border:2.5px solid var(--ink);border-radius:18px;
  padding:16px 18px;box-shadow:0 18px 44px rgba(0,0,0,.3);display:flex;flex-direction:column;gap:8px}
.ins-card b{font-size:14px}
.ins-hint{margin:0;font-size:11px;line-height:1.6;opacity:.65;font-weight:700}
.ins-card textarea, #insTa{width:100%;height:88px!important;min-height:60px!important;max-height:180px;flex:0 0 auto!important;resize:vertical;border:2px solid var(--ink);border-radius:12px;
  padding:10px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;line-height:1.6;
  background:var(--cloud);color:var(--ink)}
.ins-kinds{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.ins-kinds-left{display:flex;align-items:center;gap:6px}
.ins-md-badge{display:inline-flex;align-items:center;font-size:10.5px;font-weight:800;color:var(--teal);background:#ecfdf5;border:1px solid #a7f3d0;border-radius:999px;padding:2px 8px}
.ins-html-badge{display:inline-flex;align-items:center;font-size:10.5px;font-weight:800;color:#2563EB;background:#eff6ff;border:1px solid #bfdbfe;border-radius:999px;padding:2px 8px}
.ins-preview-btn{border:1.5px solid var(--ink);border-radius:999px;padding:3px 10px;font-size:11px;font-weight:800;cursor:pointer;background:#fff;color:var(--ink);transition:all .15s}
.ins-preview-btn:hover{background:var(--cloud)}
.ins-md-preview-pane{border:2px solid var(--ink);border-radius:12px;background:#fff;padding:12px;max-height:240px;overflow:auto;display:flex;flex-direction:column;gap:8px}
.ins-md-preview-head{display:flex;justify-content:space-between;align-items:center;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:var(--teal);border-bottom:1px solid #e5e7eb;padding-bottom:4px}
.ins-md-preview-close{background:none;border:none;cursor:pointer;font-size:12px;font-weight:900;color:var(--ink);padding:2px 6px;border-radius:4px}
.ins-md-preview-close:hover{background:#f3f4f6}
.ins-md-preview-body{font-size:13px;line-height:1.6;color:#111;overflow-wrap:anywhere}
.ins-md-preview-body :first-child{margin-top:0}
.ins-md-preview-body :last-child{margin-bottom:0}
.ins-preview-frame{width:100%;height:180px;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff;display:none}
.ins-err{min-height:15px;font-size:11px;font-weight:800;color:#c0392b}
.ins-go{display:flex;justify-content:flex-end;gap:8px}
.ins-btn{border:2.5px solid var(--ink);border-radius:999px;padding:8px 16px;font:inherit;font-size:12px;
  font-weight:900;cursor:pointer;background:#fff;color:var(--ink);transition:opacity .15s}
.ins-btn.on{background:var(--teal);color:#fff}
.ins-btn:disabled{opacity:.65;cursor:not-allowed;pointer-events:none}
@keyframes insSpin{to{transform:rotate(360deg)}}
.ins-spin{display:inline-block;width:12px;height:12px;border:2px solid rgba(255,255,255,0.4);border-top-color:#fff;border-radius:50%;animation:insSpin .75s linear infinite;vertical-align:-2px;margin-right:6px}
.resize-anchor{position:absolute;right:0;bottom:0;width:44px;height:44px;cursor:nwse-resize;z-index:8;touch-action:none;background:linear-gradient(135deg,transparent 50%,rgba(255,226,122,0.95) 50%)}
.resize-anchor::after{content:'↘';position:absolute;right:5px;bottom:2px;font-weight:900;font-size:15px}
.side-anchor{position:absolute;right:0;top:34px;bottom:44px;width:28px;max-width:30%;cursor:ew-resize;z-index:7;touch-action:none;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .15s}
.side-anchor::after{content:'';width:8px;height:54px;border-radius:999px;background:var(--ink);opacity:.35;box-shadow:0 0 0 2px rgba(255,255,255,.65)}
.item:hover .side-anchor{opacity:1}
body.resizing .side-anchor{opacity:1}
@media (pointer:coarse){.side-anchor{width:36px;opacity:.55}.side-anchor::after{width:10px;height:64px}}
/* item 9: keep the width grip off the card iframe's native scroll bar so the bar can be dragged */
.item.clipped .side-anchor{right:16px;width:16px}
body.resizing{user-select:none;cursor:nwse-resize}
body.resizing iframe{pointer-events:none}
.ph{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;background:repeating-linear-gradient(135deg,#1b1b1b 0 14px,#222 14px 28px);color:#fff;font-weight:900;pointer-events:none}
.ph-i{font-size:22px;opacity:.75}
.ph-h{font-size:11px;opacity:.55;max-width:90%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.corner{position:absolute;left:0;top:0;width:52px;height:52px;z-index:5;pointer-events:none}
.corner svg{position:absolute;inset:0;width:100%;height:100%;overflow:visible}
.corner .c-fill{fill:var(--yellow)}
.corner .c-edge{fill:none;stroke:var(--ink);stroke-width:1.6}
.corner .c-lbl{position:absolute;left:-5px;top:12px;width:44px;text-align:center;font-size:9px;font-weight:900;line-height:10px;letter-spacing:-.15px;color:var(--green);transform:rotate(-45deg);white-space:nowrap}
.corner.co.ok .c-lbl{color:#fff}
.corner-dim{position:absolute;left:50%;bottom:7px;transform:translateX(-50%);z-index:6;pointer-events:none;background:rgba(17,17,17,.8);color:#fff;font-size:10px;font-weight:800;padding:2px 8px;border-radius:99px;opacity:0;transition:opacity .15s;white-space:nowrap}
.item:hover .corner-dim,.item.dragging .corner-dim{opacity:1}
.bar-zone{position:sticky;top:8px;z-index:70;display:flex;flex-direction:column;align-items:center;gap:8px;margin:0 0 14px}
.zone-a{position:relative;overflow:hidden;width:100%;margin:0 auto 18px;transition:height .45s cubic-bezier(.2,.8,.2,1)}
.zone-a[hidden]{display:none}
.za-track{position:relative;height:100%}
.za-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:7;width:38px;height:38px;border-radius:50%;border:2.5px solid var(--ink);background:var(--yellow);color:var(--ink);font-size:24px;font-weight:900;line-height:1;cursor:pointer;display:grid;place-items:center;padding:0 0 3px;box-shadow:0 6px 16px rgba(0,0,0,.22);opacity:.55;transition:opacity .15s,transform .15s}
.zone-a:hover .za-arrow,.za-arrow:focus-visible{opacity:1}
.za-arrow:hover{transform:translateY(-50%) scale(1.08)}
.za-arrow[hidden]{display:none}
.za-l{left:6px}.za-r{right:6px}
@media (max-width:560px){.za-arrow{width:32px;height:32px;font-size:20px;opacity:.85}}
.za-track>.item{position:absolute;left:0;top:0;transition:left .6s cubic-bezier(.2,.8,.2,1)}
@media (prefers-reduced-motion:reduce){.za-track>.item,.zone-a,.za-arrow{transition:none}}
.fx-bee{position:fixed;left:0;top:0;z-index:9;pointer-events:none;width:max-content;will-change:transform;filter:drop-shadow(0 8px 14px rgba(0,0,0,.28))}
.fx-bee{transform-style:preserve-3d}
.fx-bee img,.fx-bee svg.zdog-svg{display:block;width:150px;height:120px;backface-visibility:visible;overflow:visible;pointer-events:auto;cursor:grab}
.fx-bee .wob{display:block;transform-style:preserve-3d;animation:fxwob .42s ease-in-out infinite alternate}
@keyframes fxwob{from{transform:translateY(-6px) rotateX(10deg) rotateZ(-4deg)}to{transform:translateY(6px) rotateX(-8deg) rotateZ(4deg)}}
@media (max-width:560px){.fx-bee img,.fx-bee svg.zdog-svg{width:110px;height:88px}}
.fx-spark{position:fixed;z-index:8;pointer-events:none;width:16px;height:16px;border-radius:50%;background:var(--yellow);box-shadow:0 0 22px 6px rgba(255,226,122,.9);animation:fxspark 1.2s ease-out forwards}
@keyframes fxspark{from{transform:scale(1);opacity:.95}to{transform:scale(.2) translateY(18px);opacity:0}}
.ver-badge{position:fixed;top:10px;right:12px;z-index:75;padding:5px 10px;border:2px solid var(--ink);border-radius:999px;background:rgba(255,255,255,.88);color:var(--ink);font-size:11px;font-weight:900;letter-spacing:.04em;pointer-events:none;box-shadow:0 4px 12px rgba(0,0,0,.12)}
@media (max-width:420px){.ver-badge{top:auto;bottom:10px;right:8px;font-size:10px;padding:4px 8px;opacity:.85}}
.empty{padding:40px 16px;text-align:center;opacity:.55;font-weight:800}
.fab-wrap{position:relative;z-index:1;max-width:100%;display:flex;gap:8px;align-items:center;background:#fff;border:2.5px solid var(--ink);border-radius:999px;padding:6px 12px;box-shadow:0 8px 24px rgba(0,0,0,.15)}
.fab{background:var(--teal);color:#fff;border:2px solid var(--ink);border-radius:999px;padding:9px 14px;font-weight:900;cursor:pointer;font-size:12px;max-width:34vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lang-select{border:2px solid var(--ink);border-radius:999px;padding:7px 12px;font-weight:800;background:#fff;font-size:12px;cursor:pointer;outline:none}
button,select{font-family:inherit}
@media (max-width:760px){
  .fab-wrap{flex-wrap:wrap;justify-content:center;border-radius:22px;padding:8px 10px}
  .fab{max-width:46vw}
}
.menu-wrap{position:relative;flex:0 0 auto}
.pill{display:inline-flex;align-items:center;gap:6px;background:#fff;border:2px solid var(--ink);border-radius:999px;padding:7px 10px;font-weight:800;font-size:12px;cursor:pointer;white-space:nowrap;color:var(--ink)}
.pill.random{background:var(--yellow)}
.pill .caret{font-size:9px;transition:transform .15s}
.pill[aria-expanded="true"] .caret{transform:rotate(180deg)}
.menu{position:absolute;top:calc(100% + 12px);left:50%;transform:translateX(-50%);min-width:176px;background:#fff;border:2.5px solid var(--ink);border-radius:16px;padding:6px;box-shadow:0 12px 28px rgba(0,0,0,.18);display:flex;flex-direction:column;gap:2px}
.menu[hidden]{display:none}
.menu button{display:flex;align-items:center;gap:10px;width:100%;background:none;border:0;border-radius:10px;padding:9px 10px;font-size:12px;font-weight:800;text-align:left;cursor:pointer;color:var(--ink)}
.menu button:hover,.menu button:focus-visible{background:var(--cloud);outline:none}
.menu button.on{background:var(--ink);color:#fff}
.menu button[data-cols="0"].on{background:var(--yellow);color:var(--ink);box-shadow:inset 0 0 0 2px var(--ink)}
.menu hr{border:0;border-top:1.5px dashed rgba(0,0,0,.25);margin:4px 6px}
.mi{display:inline-flex;justify-content:center;width:22px;flex:0 0 22px}
.cn{font-size:10px;font-weight:900;margin-left:1px}
.fx-group{display:contents}
.fx-group[hidden]{display:none}
.ci{display:inline-flex;gap:2px;width:20px;height:12px}.ci i{flex:1;background:currentColor;border-radius:1.5px}
.pill.exp{background:#F3E5F5;border-color:var(--ink)}
#copyBtn{margin-left:6px}
body.empty-wall .pill.exp{display:none}
.pill .lbl{display:none}
.pill.zone .lbl{display:inline}
.pill{padding:7px 9px}
.pill.zone{padding:7px 11px}
@media (max-width:560px){.fab{max-width:28vw}}
@media (max-width:420px){.fab-wrap{gap:4px;padding:4px 6px}.pill{padding:6px 7px}.fab{padding:8px 10px;max-width:22vw}.lang-select{padding:6px 4px;max-width:64px}}
@media (prefers-reduced-motion:reduce){.pill .caret{transition:none}}
button:focus-visible,.tool-btn:focus-within,select:focus-visible{outline:3px solid var(--teal);outline-offset:2px}
.fab.badge{cursor:default}
.pill.filt.active{background:var(--yellow)}
.pill.fx[aria-pressed="false"]{opacity:.55}
.pill.fx[aria-pressed="true"]{background:var(--yellow)}
.pill.snd[aria-pressed="true"]{background:var(--teal);color:#fff}
.pill.filt[aria-pressed="true"]{box-shadow:inset 0 0 0 2px var(--ink)}
.filter-bar{position:relative;z-index:1;width:min(920px,100%);display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:#fff;border:2.5px solid var(--ink);border-radius:20px;padding:8px 10px;box-shadow:0 8px 24px rgba(0,0,0,.12)}
.filter-bar[hidden]{display:none}
.filter-bar input{flex:1 1 220px;min-width:0;border:2px solid var(--ink);border-radius:999px;padding:8px 14px;font:inherit;font-size:13px;font-weight:700;background:var(--cloud);color:var(--ink)}
.filter-bar select{border:2px solid var(--ink);border-radius:999px;padding:7px 10px;font:inherit;font-size:12px;font-weight:800;background:#fff;color:var(--ink);max-width:100%}
.filter-bar input:focus-visible,.filter-bar select:focus-visible{outline:3px solid var(--teal);outline-offset:2px}
.f-count{font-size:12px;font-weight:900;opacity:.7;white-space:nowrap}
.f-clear{border:2px solid var(--ink);border-radius:999px;padding:7px 12px;font:inherit;font-size:12px;font-weight:800;background:#fff;cursor:pointer;color:var(--ink)}
.masonry>.item.f-out{display:none}
.no-match{padding:48px 16px;text-align:center;font-weight:800;opacity:.55}
.pill.car[aria-pressed="true"]{background:var(--ink);color:#fff}
.masonry.carousel{overflow-x:auto;overflow-y:hidden;scroll-snap-type:x mandatory;scroll-behavior:smooth;overscroll-behavior-x:contain;scrollbar-width:none}
.masonry.carousel::-webkit-scrollbar{display:none}
.masonry.carousel>.item{scroll-snap-align:start}
.masonry.carousel.ready>.item{transition:none}
.masonry.carousel.ready{transition:height .3s ease}
.masonry.carousel .resize-anchor,.masonry.carousel .side-anchor{display:none}
.car-nav{position:relative;z-index:6;margin:0 auto 14px;width:max-content;max-width:calc(100% - 8px);display:flex;align-items:center;gap:10px;background:#fff;border:2.5px solid var(--ink);border-radius:999px;padding:6px 8px;box-shadow:0 8px 24px rgba(0,0,0,.12)}
.car-nav[hidden]{display:none}
.car-arrow{width:34px;height:34px;border-radius:50%;border:2px solid var(--ink);background:var(--yellow);font-size:20px;font-weight:900;line-height:1;cursor:pointer;color:var(--ink);flex:0 0 auto}
.car-arrow:disabled{opacity:.35;cursor:default}
.car-side{position:absolute;z-index:6;width:44px;height:44px;border-radius:50%;border:2.5px solid var(--ink);background:var(--yellow);color:var(--ink);font-size:28px;font-weight:900;line-height:1;cursor:pointer;box-shadow:0 6px 16px rgba(0,0,0,.22);display:grid;place-items:center;padding:0 0 4px;transition:opacity .15s,transform .15s}
.car-side[hidden]{display:none}
.car-side:hover:not(:disabled){transform:scale(1.08)}
.car-side:disabled{opacity:.25;cursor:default}
@media (max-width:560px){.car-side{width:36px;height:36px;font-size:22px}}
@media (prefers-reduced-motion:reduce){.car-side{transition:none}}
.car-dots{display:flex;align-items:center;gap:6px;overflow:hidden}
.car-dot{width:10px;height:10px;padding:0;border-radius:50%;border:2px solid var(--ink);background:#fff;cursor:pointer;flex:0 0 auto;transition:width .2s,background .2s}
.car-dot[aria-current="true"]{width:24px;border-radius:999px;background:var(--teal)}
.car-count{font-size:12px;font-weight:900;min-width:54px;text-align:center}
@media (prefers-reduced-motion:reduce){.masonry.carousel{scroll-behavior:auto}.car-dot{transition:none}}
.pill.shuffle:hover{background:var(--cloud)}
.pill[aria-busy="true"]{opacity:.55;cursor:progress}
body.shuffling iframe{pointer-events:none}
body.shuffling{overflow-x:hidden}
.menu button[data-cols="-1"].on{background:var(--teal);color:#fff}
.ins-kinds{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ins-kind{border:2px solid var(--ink);border-radius:999px;background:#fff;padding:4px 11px;font:inherit;font-size:11px;font-weight:900;cursor:pointer}
.ins-kind:hover{background:var(--yellow)}
.ins-kinds-note{font-size:10.5px;font-weight:700;opacity:.55}
.spr-box{display:flex;flex-direction:column;gap:6px;border:2px dashed var(--ink);border-radius:14px;padding:10px 12px;background:#fff}
.spr-box>b{font-size:12px}
.spr-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.spr{position:relative;width:74px;height:74px;border:2px solid var(--ink);border-radius:12px;background:var(--cloud);display:flex;align-items:center;justify-content:center}
.spr img{max-width:58px;max-height:58px;object-fit:contain}
.spr-tag{position:absolute;left:4px;top:3px;font-size:8.5px;font-weight:900;color:var(--green)}
.spr-acts{position:absolute;right:-6px;bottom:-8px;display:flex;gap:3px}
.spr-acts button,.spr-add,.spr-reset{border:2px solid var(--ink);background:#fff;border-radius:50%;width:24px;height:24px;padding:0;font:inherit;font-size:11px;font-weight:900;cursor:pointer;line-height:1}
.spr-acts button[data-rep]{background:var(--yellow)}
.spr-add{width:74px;height:74px;border-radius:12px;border-style:dashed;font-size:22px}
.spr-reset{width:30px;height:30px}
.spr-acts button:hover,.spr-add:hover,.spr-reset:hover{background:var(--teal);color:#fff}
.spr-note{font-size:11px;font-weight:800;opacity:.6}.spr-note.bad{color:#c0392b;opacity:1}
.pill.fx:disabled{opacity:.35;cursor:not-allowed}
.fold{display:none;position:absolute;left:0;right:0;bottom:0;z-index:7;height:74px;align-items:flex-end;justify-content:center;padding-bottom:10px;pointer-events:none;background:linear-gradient(to bottom,rgba(255,255,255,0),rgba(255,255,255,.92) 62%)}
.item.clipped .fold{display:flex}
.item.unfolded .fold{display:flex;height:auto;background:none}
.fold-btn{pointer-events:auto;border:2px solid var(--ink);background:var(--yellow);color:var(--ink);border-radius:999px;padding:4px 14px;font:inherit;font-size:12px;font-weight:900;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.15)}
.fold-btn:hover{background:var(--teal);color:#fff}
.item.unfolded .fold-btn{background:#fff}
.masonry.carousel .fold{display:none!important}
.del,.edit,.tog{position:absolute;top:6px;width:18px;height:18px;padding:0;border-radius:50%;border:0;cursor:pointer;z-index:9;font-weight:900;font-size:9px;line-height:18px;text-align:center;opacity:.35;transition:opacity .15s}
.del{right:6px;background:rgba(0,0,0,.6);color:#fff}
.edit{right:28px;background:rgba(255,255,255,.75);color:#111}
.tog{right:50px;background:rgba(255,255,255,.75);color:#111;font-size:10px}
.item:hover .del,.item:hover .edit,.item:hover .tog,.del:focus-visible,.edit:focus-visible,.tog:focus-visible{opacity:1}
@media (pointer:coarse){.del,.edit,.tog{width:22px;height:22px;line-height:22px;font-size:10px;opacity:.5}.edit{right:32px}.tog{right:58px}}
@media (prefers-reduced-motion:reduce){.del,.edit,.tog{transition:none}}
.import-panel{position:fixed;top:68px;left:50%;transform:translateX(-50%);width:min(920px,96vw);background:#fff;border:2.5px solid var(--ink);border-radius:20px;padding:16px;max-height:92vh;display:flex;flex-direction:column;z-index:85;overflow:auto}
.import-panel.hidden{display:none}
.import-panel textarea, .ta-wrap textarea, textarea#input{width:100%;flex:1;min-height:380px;border:2px solid var(--ink);border-radius:12px;padding:12px;font-family:monospace;background:var(--cloud);line-height:1.8;font-size:12px}
.ta-wrap{display:flex;gap:8px;flex:1;min-height:380px;position:relative}
.ta-wrap.sized{flex:0 0 auto;height:var(--ta-h);min-height:0}
.ta-wrap.sized #input{min-height:0;height:100%}
#input{resize:none}
.ta-grip{position:absolute;right:6px;bottom:6px;width:22px;height:22px;z-index:4;
  cursor:ns-resize;border-radius:6px;touch-action:none;
  background:linear-gradient(135deg,transparent 0 46%,var(--ink) 46% 54%,transparent 54% 70%,var(--ink) 70% 78%,transparent 78%);
  opacity:.38;transition:opacity .15s}
.ta-grip:hover,.ta-grip:focus-visible{opacity:.95;outline:none}
.ta-grip:focus-visible{box-shadow:0 0 0 2px var(--teal)}
.ta-wrap #input{flex:1;min-height:0;scrollbar-width:none}
.ta-wrap #input::-webkit-scrollbar{width:0;height:0}
.import-panel{scrollbar-width:auto}
.toolbar{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0;align-items:center}
.tool-btn{border:2px solid var(--ink);border-radius:999px;padding:8px 14px;font-size:11px;font-weight:800;cursor:pointer;background:#fff}
.tool-btn.primary{background:var(--teal);color:#fff}
.zdrag{display:flex;align-items:center;gap:6px}
.zchip{display:inline-flex;align-items:center;gap:5px;border:2px solid var(--ink);border-radius:999px;padding:5px 12px;font-size:11px;font-weight:900;background:#fff;cursor:grab;user-select:none}
.zchip .grip{opacity:.45;font-size:12px}
.zchip.fixed{background:var(--cloud);cursor:default;opacity:.7}
.zchip.drag{opacity:.45;cursor:grabbing}
.zchip.over{box-shadow:0 0 0 3px var(--teal)}
.tabs{display:flex;gap:6px;margin:6px 0 2px}
.tab{border:2px solid var(--ink);border-radius:999px;padding:6px 14px;font:inherit;font-size:11px;font-weight:900;background:#fff;cursor:pointer;color:var(--ink)}
.tab[aria-selected="true"]{background:var(--ink);color:#fff}
.pane[hidden]{display:none}
.pane-pics, .pane-media{display:flex;flex-direction:column;gap:10px;flex:1;min-height:320px}
.drop{border:2.5px dashed var(--ink);border-radius:16px;padding:22px;text-align:center;font-weight:800;font-size:12px;background:var(--cloud);cursor:pointer}
.drop.over{background:var(--yellow)}
.media-drop{border:2.5px dashed var(--ink);border-radius:16px;padding:36px 20px;text-align:center;background:#fafafa;cursor:pointer;transition:all .18s ease}
.media-drop:hover,.media-drop.over{background:#f0fdf4;border-color:#10b981}
.media-item{display:flex;align-items:center;justify-content:space-between;gap:12px;background:#fff;border:1.5px solid rgba(0,0,0,.15);border-radius:12px;padding:8px 12px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.media-thumb{width:46px;height:46px;border-radius:8px;object-fit:cover;background:#f8fafc;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;overflow:hidden;border:1px solid #e2e8f0}
.media-badge{font-size:9.5px;font-weight:900;text-transform:uppercase;padding:2px 7px;border-radius:999px;letter-spacing:.5px}
.media-badge.img{background:#e0f2fe;color:#0369a1}
.media-badge.pdf{background:#fee2e2;color:#b91c1c}
.media-badge.vid{background:#f3e8ff;color:#7e22ce}
.media-badge.aud{background:#fef3c7;color:#b45309}
.media-badge.htm{background:#dcfce7;color:#15803d}
.media-badge.mdl{background:#e0e7ff;color:#4338ca}
.pics{display:flex;flex-wrap:wrap;gap:10px;overflow:auto;max-height:280px}
.pic .m3d{width:100%;height:76px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;background:radial-gradient(120% 120% at 50% 0%,#fff,#E6E2DB);color:#111;font-weight:900}
.pic .m3d b{font-size:19px;line-height:1}
.pic .m3d span{font-size:9px;letter-spacing:.09em;opacity:.7}
.pic .m3d i{font-size:9px;font-style:normal;opacity:.5;max-width:92%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pic{width:150px;border:2px solid var(--ink);border-radius:12px;overflow:hidden;background:var(--cloud)}
.pic img{display:block;width:100%;height:92px;object-fit:contain;background:#fff}
.pic .row{display:flex;align-items:center;gap:4px;padding:6px;font-size:10px;font-weight:800}
.pic select{flex:1;min-width:0;border:1.5px solid var(--ink);border-radius:999px;padding:3px 4px;font:inherit;font-size:10px;font-weight:800;background:#fff}
.pic button{border:1.5px solid var(--ink);background:#fff;border-radius:999px;width:22px;height:22px;cursor:pointer;font-weight:900}
.meta-fields{display:grid;grid-template-columns:2fr 1.5fr 1fr;gap:8px;margin:4px 0 8px}
.meta-fields[hidden]{display:none}
.meta-fields label{display:flex;flex-direction:column;gap:3px;font-size:10px;font-weight:900;opacity:.85}
.meta-fields input{border:2px solid var(--ink);border-radius:10px;padding:7px 10px;font:inherit;font-size:12px;font-weight:700;background:var(--cloud);color:var(--ink)}
@media (max-width:560px){.meta-fields{grid-template-columns:1fr}}
.globe{background:#fff;border:2.5px solid var(--ink);border-radius:50%;width:36px;height:36px;cursor:pointer;flex:0 0 auto}
@media (max-width:420px){.globe{display:none}}
body:not(.is-owner) .globe, body.not-owner .globe, body.guest .globe, body.ro:not(.is-owner) .globe{display:none !important}
.tool-btn.busy{opacity:.55;cursor:progress;position:relative;padding-right:30px}
.tool-btn.busy::after{content:'';position:absolute;right:9px;top:50%;width:12px;height:12px;margin-top:-6px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:ezspin .8s linear infinite}
@keyframes ezspin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.tool-btn.busy::after{animation:none}}
.toast{position:fixed;left:50%;bottom:24px;transform:translate(-50%,20px);background:var(--ink);color:#fff;padding:10px 16px;border-radius:999px;font-size:12px;font-weight:800;opacity:0;pointer-events:none;transition:opacity .2s,transform .2s;z-index:95;max-width:92vw}
.toast.show{opacity:1;transform:translate(-50%,0)}
@media (prefers-reduced-motion:reduce){.toast{transition:none}}

/* ============================================================
 * Hide floating menu & bar completely on mobile and tablet
 * ============================================================ */
@media (max-width: 1024px) {
  .bar-zone,
  .fab-wrap {
    display: none !important;
  }
}
/* card-rule CSS (2.1, 3.1, 4.1, 4.2) lives in wall-core.js (EZ_CARD_CSS), shared with the a19/a22 pages */
.ez-dlg{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center}
.ez-dlg .box{background:#fff;border:2.5px solid var(--ink);border-radius:16px;max-width:520px;width:92%;padding:16px 18px;font:14px/1.45 system-ui,sans-serif}
.ez-dlg ul{margin:8px 0 12px;padding-left:18px;max-height:40vh;overflow:auto}
.ez-dlg .row{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
`;

/* ============================================================
 * Multi-line HTML/Markdown splitter (mirrors editor logic)
 * ============================================================ */
const SEP_RE = /^[━─—–\-_*•·・－ー]{3,}$/;
const ZONE_A_RE = /^={3,}$/;
const ZONE_C_RE = /^#{1,}$/;
const VOID_TAGS = /^(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)$/i;

function tagDepth(s: string): number {
  let depth = 0;
  const re = /<(\/?)([a-zA-Z][\w-]*)\b[^>]*?(\/?)>/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(s))) {
    if (VOID_TAGS.test(m[2]) || m[3]) continue;
    depth += m[1] ? -1 : 1;
  }
  return depth;
}
const isClosed = (s: string) => s.lastIndexOf('<') <= s.lastIndexOf('>') && tagDepth(s) <= 0;

function splitBlocks(text: string): string[] {
  let cleaned = String(text || '').trim();

  // Preserve <wiki> and <co-wiki> blocks cleanly without discarding <wiki>
  const wikiMatches = [...cleaned.matchAll(/<wiki\b[^>]*>([\s\S]*?)<\/wiki>/gi)];
  const cowikiMatches = [...cleaned.matchAll(/<(?:co-wiki|cowiki)\b[^>]*>([\s\S]*?)<\/(?:co-wiki|cowiki)>/gi)];

  if (wikiMatches.length > 0 || cowikiMatches.length > 0) {
    const parts: string[] = [];
    wikiMatches.forEach(m => {
      const inner = m[1].trim();
      if (inner) parts.push(inner);
    });
    cowikiMatches.forEach(m => {
      const inner = m[1].trim();
      if (inner) parts.push(inner);
    });
    cleaned = parts.join('\n━━━━\n');
  }

  const out: string[] = [];
  let buf: string | null = null;
  let md: string | null = null;
  for (const rawLine of cleaned.split(/\r?\n/)) {
    const line = rawLine.trim();
    if (md !== null) {
      md += '\n' + rawLine;
      if (/<\/md>\s*$/i.test(line)) { out.push(md.trim()); md = null; }
      continue;
    }
    if (/^<wiki\b/i.test(line) || /^<\/wiki>/i.test(line) || /^<(?:co-wiki|cowiki)\b/i.test(line) || /^<\/(?:co-wiki|cowiki)>/i.test(line)) {
      if (buf !== null) { out.push(buf.trim()); buf = null; }
      continue;
    }
    if (buf === null && /^<md>/i.test(line)) {
      if (/<\/md>\s*$/i.test(line) && line.length > 4) out.push(line);
      else md = rawLine;
      continue;
    }
    if (buf !== null) {
      if (ZONE_C_RE.test(line) || ZONE_A_RE.test(line) || SEP_RE.test(line) ||
          (/^https?:\/\/\S+$/i.test(line) && buf.lastIndexOf('<') <= buf.lastIndexOf('>'))) {
        out.push(buf.trim()); buf = null;
      } else {
        buf += '\n' + rawLine;
        if (isClosed(buf)) { out.push(buf.trim()); buf = null; }
        continue;
      }
    }
    if (ZONE_C_RE.test(line)) { out.push('__ZONE_C__'); continue; }
    if (ZONE_A_RE.test(line)) { out.push('__ZONE_A__'); continue; }
    if (!line || SEP_RE.test(line)) continue;
    if (line.startsWith('<')) {
      if (/^<script\b/i.test(line) && out.length && out[out.length - 1].startsWith('<'))
        buf = out.pop() + '\n' + rawLine;
      else buf = rawLine;
      if (isClosed(buf)) { out.push(buf.trim()); buf = null; }
      continue;
    }
    const found = line.match(/https?:\/\/[^\s"'<>]+/gi);
    if (found) out.push(...found);
    else out.push(line);
  }
  if (buf !== null) out.push(buf.trim());
  if (md !== null) out.push(md.trim());
  return out;
}

function databaseCardsToList(dbCards: any[], _isOwnerUser?: boolean, _authUserId?: any): any[] {
  /* Server order is kept as is (zone, mother, its children newest first).
     Role/status come from the server only: being the owner does not turn
     Co-WiKi cards into WiKi cards. */
  const seenCids = new Set<string>();
  return dbCards.map((c, index) => {
    if (!c || typeof c !== 'object') return null;
    const cid = String(c.cid || c.id || '');
    if (cid && seenCids.has(cid)) return null;
    // `content` since pass 4 (`raw` is the deprecated legacy key)
    const raw = String(c.content || c.raw || c._raw || c.url || c.html || '').trim();
    if (!raw && !c.mdl) return null;
    if (cid) seenCids.add(cid);

    const parsed = parseOneBlock(raw) || {};
    const role: 'mother' | 'child' = c.role === 'child' || (c.role !== 'mother' && !!c.anchor) ? 'child' : 'mother';
    const zone: 'a' | 'b' | 'c' = role === 'child' ? 'b' : (c.zone === 'a' || c.zone === 'c' ? c.zone : 'b');
    const status = c.status === 'pending' ? 'pending' : 'approved';
    const itemType = parsed.type || (parsed.html ? 'html' : parsed.md ? 'md' : 'web');

    const item: any = {
      _raw: raw,
      _id: c.cid || `db_${c.id || index}`,
      cid: c.cid || `db_${c.id || index}`,
      type: itemType,
      role,
      mother_cid: role === 'child' ? (c.anchor || null) : null,
      origin: role === 'child' ? 'cowiki' : 'wiki',
      w: Number(c.w) || Number(parsed.w) || 340,
      h: Number(c.h) || Number(parsed.h) || 220,
      zone,
      guest: role === 'child',
      ok: role === 'child' ? status === 'approved' : true,
      status: role === 'child' ? status : 'approved',
      user_id: c.user_id,
      position: c.position !== undefined ? Number(c.position) : index,
      at: c.at || (c.created_at ? new Date(c.created_at).getTime() : Date.now()),
    };

    ['url', 'id', 'html', 'md', 'mdl', 'title', 'tags', 'pub', 'dur', 'span', 'rh', 'emb', 'blk', 'manual', 'pic', 'mh'].forEach(k => {
      if (parsed[k] !== undefined) item[k] = parsed[k];
      else if (c[k] !== undefined) item[k] = c[k];
    });

    if (!item.type || item.type === 'web') {
      const yt = String(item.url || '').match(
        /(?:youtube\.com\/(?:embed\/|watch\?v=|shorts\/|live\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/
      );
      if (yt) { item.type = 'youtube'; item.id = yt[1]; }
    }
    return item;
  }).filter(Boolean);
}

/* ============================================================
 * React component
 * ============================================================ */
const EzSheetPreview: React.FC<EzSheetPreviewProps> = ({
  cards,
  content = '',
  title = 'EzSheet Preview',
  className = '',
  height = 900,
  autoHeight = false,
  frameless = false,
  minHeight = 240,
  openEditor = false,
  wallId = 'ezsheet',
  lang = 'en',
  cols,
  car,
  filter,
  editable = false,
  zones,
  zorder,
  flying = true,
  sound,
  sprites,
  syncUrl,
  frameCheckUrl,
  metaUrl,
  auth,
  isOwner = false,
  slug,
  funnel,
  messageId,
  domains,
  onItemsChange,
  onPushToWall,
  rev,
}) => {
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const [error, setError] = useState<string | null>(null);
  const [fetchedCards, setFetchedCards] = useState<any[] | null>(null);
  const [fetchedRev, setFetchedRev] = useState<number | undefined>(undefined);
  const onItemsChangeRef = useRef(onItemsChange);
  onItemsChangeRef.current = onItemsChange;
  const onPushToWallRef = useRef(onPushToWall);
  onPushToWallRef.current = onPushToWall;
  const mountedWallKeyRef = useRef<string | null>(null);
  const internalUpdateRef = useRef<boolean>(false);
  const lastPostedRef = useRef<string>("");
  /* item 10: auto height lives in a ref and is written straight to the iframe, never in React
     state, so a height change can't re-render this component or re-post the payload. */
  const autoHRef = useRef<number | null>(null);
  const autoHeightRef = useRef(autoHeight);
  autoHeightRef.current = autoHeight;
  const heightDamperRef = useRef<ReturnType<typeof createWallHeightDamper> | null>(null);
  if (!heightDamperRef.current) {
    heightDamperRef.current = createWallHeightDamper((h: number) => {
      autoHRef.current = h;
      const f = iframeRef.current;
      if (f && autoHeightRef.current) f.style.height = h + 'px';
    }, { minH: minHeight });
  }

  useEffect(() => {
    if (cards && Array.isArray(cards)) {
      setFetchedCards(cards);
      return;
    }
    if (wallId) {
      const origin = typeof window !== 'undefined' && window.location ? window.location.origin : '';
      const url = `${origin}/api/wall/${encodeURIComponent(wallId)}/cards`;
      fetch(url)
        .then(r => r.ok ? r.json() : null)
        .then(data => {
          if (data && Array.isArray(data.cards)) {
            setFetchedCards(data.cards);
            if (typeof data.rev === 'number') setFetchedRev(data.rev);
          }
        })
        .catch(() => {});
    }
  }, [cards, wallId]);

  const resolvedAuth = useMemo(() => {
    if (auth?.user) return auth;
    if (typeof window !== 'undefined') {
      if ((window as any).auth?.user) {
        return (window as any).auth;
      }
      try {
        const appEl = document.getElementById('app');
        if (appEl?.dataset?.page) {
          const p = JSON.parse(appEl.dataset.page);
          if (p?.props?.auth?.user) {
            return p.props.auth;
          }
        }
      } catch (e) {}
    }
    return auth || { user: null };
  }, [auth]);

  const payload = useMemo(() => {
    const activeCards = (cards && Array.isArray(cards) && cards.length > 0) ? cards : (fetchedCards && fetchedCards.length > 0 ? fetchedCards : null);
    if (!content && !wallId && (!activeCards || activeCards.length === 0)) return null;

    let list: any[] = [];
    let detectedId = wallId;
    const resolvedSlug = (() => {
      if (slug && slug !== 'ezsheet') return slug;
      if (typeof window !== 'undefined') {
        const parts = (window.location.pathname || '').split('/').filter(Boolean);
        const cand = (parts[0] === 'X' && parts[1]) ? parts[1] : parts[0];
        const blocked = ['dashboard', 'marketplace', 'login', 'register', 'ezbar', 'ezsheet'];
        if (cand && !blocked.includes(cand.toLowerCase())) {
          return cand;
        }
      }
      return slug || '';
    })();
    const resolvedFunnel = funnel || (typeof window !== 'undefined' && (window as any).__EZ_FUNNEL) || '';
    let detectedCols = typeof cols === 'number' ? cols : undefined;
    let detectedCar = typeof car === 'boolean' ? car : undefined;
    let detectedFilter: boolean | undefined = typeof filter === 'boolean' ? filter : undefined;
    let detectedLang = lang;
    let detectedZones = zones;
    let detectedZorder = zorder;
    let detectedFx = {
      fly: flying !== false,
      snd: sound !== false,
    };

    // Server cards are already in display order; never re-sort them here.
    if (activeCards && activeCards.length > 0) {
      list = databaseCardsToList(activeCards);
      const trimmed = String(content || '').trim();
      if (trimmed) {
        const blocks = splitBlocks(trimmed);
        for (const b of blocks) {
          const block = b.trim();
          if (!block || b === '__ZONE_C__' || b === '__ZONE_A__') continue;
          const it = parseOneBlock(block);
          if (it && it.type === 'html' && /<img\b/i.test(it.html || '')) {
            const m = (it.html.match(/<img[^>]+src=["']([^"']+)["']/i) || [])[1];
            if (m) {
              const exists = list.some((existing) => {
                const exRaw = existing._raw || existing.html || existing.url || '';
                return exRaw.includes(m);
              });
              if (!exists) {
                it.zone = 'b';
                it.w = it.w || 340;
                it.h = it.h || 260;
                list.push(it);
              }
            }
          }
        }
      }
    } else {
      const trimmed = String(content || '').trim();

      const dataMatch = trimmed.match(
        /<script\s+id=["']wall-data["'][^>]*>([\s\S]*?)<\/script>/i
      );
      if (dataMatch?.[1]) {
        try {
          const parsed = JSON.parse(dataMatch[1]);
          if (parsed && typeof parsed === 'object') {
            if (parsed.id) detectedId = String(parsed.id);
            if (Array.isArray(parsed.list)) list = parsed.list;
            else if (Array.isArray(parsed)) list = parsed;
            if (typeof parsed.cols === 'number') detectedCols = parsed.cols;
            if (typeof parsed.car === 'boolean') detectedCar = parsed.car;
            if (typeof parsed.filter === 'boolean') detectedFilter = parsed.filter;
            if (typeof parsed.lang === 'string') detectedLang = parsed.lang;
            if (parsed.zones && typeof parsed.zones === 'object') detectedZones = parsed.zones;
            if (Array.isArray(parsed.zorder)) detectedZorder = parsed.zorder;
            if (parsed.fx && typeof parsed.fx === 'object') detectedFx = parsed.fx;
          }
        } catch (e) {
          console.warn('EzSheetPreview: failed to parse wall-data JSON', e);
        }
      }

      if (list.length === 0 && /^[\[{]/.test(trimmed)) {
        try {
          const parsed = JSON.parse(trimmed);
          if (Array.isArray(parsed)) {
            list = parsed;
          } else if (parsed && typeof parsed === 'object') {
            if (parsed.id) detectedId = String(parsed.id);
            if (Array.isArray(parsed.list)) list = parsed.list;
            if (typeof parsed.cols === 'number') detectedCols = parsed.cols;
            if (typeof parsed.car === 'boolean') detectedCar = parsed.car;
            if (typeof parsed.filter === 'boolean') detectedFilter = parsed.filter;
            if (typeof parsed.lang === 'string') detectedLang = parsed.lang;
            if (parsed.zones && typeof parsed.zones === 'object') detectedZones = parsed.zones;
            if (Array.isArray(parsed.zorder)) detectedZorder = parsed.zorder;
            if (parsed.fx && typeof parsed.fx === 'object') detectedFx = parsed.fx;
          }
        } catch (e) {
          console.warn('EzSheetPreview: failed to parse JSON content', e);
        }
      }

      if (list.length === 0 && trimmed) {
        const blocks = splitBlocks(trimmed);
        const out: any[] = [];
        let buf: any[] = [];
        const flush = (zone: 'a' | 'b' | 'c') => {
          buf.forEach((o) => { o.zone = zone; out.push(o); });
          buf = [];
        };
        for (const b of blocks) {
          if (b === '__ZONE_C__') { flush('c'); continue; }
          if (b === '__ZONE_A__') { flush('a'); continue; }
          const block = b.trim();
          if (!block) continue;
          const it = parseOneBlock(block);
          if (it) buf.push(it);
        }
        flush('b');
        list = out;
      }
    }

    if (list.length === 0 && !wallId) return null;

    const seenNormCids = new Set<string>();
    const seenNormRaws = new Set<string>();
    const normalized = list
      .map((item) => {
        if (!item || typeof item !== 'object') return null;
        const cid = String(item.cid || item._id || '');
        if (cid && seenNormCids.has(cid)) return null;

        const raw0 =
          item._raw ||
          (item.type === 'html' && item.html) ||
          (item.type === 'md' && item.md && `<md>${item.md}</md>`) ||
          item.url ||
          '';
        const raw = raw0;
        // de-dupe by text only for cards without a server cid (pasted content)
        if (!item.cid && raw && seenNormRaws.has(raw)) return null;

        if (cid) seenNormCids.add(cid);
        if (raw) seenNormRaws.add(raw);

        const out: any = {
          _raw: raw,
          type: item.type || (item.html ? 'html' : item.md ? 'md' : 'web'),
          w: Number(item.w) || 340,
          h: Number(item.h) || 220,
          zone: item.zone === 'a' || item.zone === 'c' ? item.zone : 'b',
        };
        [
          'cid','_id','guest','ok','status','user_id','role','mother_cid','anchor','url','id','html','md','mdl','title','tags','at','pub','dur','span','rh',
          'emb','blk','manual','pic','mh',
        ].forEach((k) => {
          if (item[k] !== undefined) out[k] = item[k];
        });
        if (!out.type || out.type === 'web') {
          const yt = String(out.url || '').match(
            /(?:youtube\.com\/(?:embed\/|watch\?v=|shorts\/|live\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/
          );
          if (yt) { out.type = 'youtube'; out.id = yt[1]; }
        }
        return out;
      })
      .filter(Boolean) as any[];

    return {
      id: detectedId,
      slug: (resolvedSlug && resolvedSlug !== 'ezsheet') ? resolvedSlug : undefined,
      funnel: (resolvedFunnel && resolvedFunnel !== 'ezsheet') ? resolvedFunnel : undefined,
      cols: detectedCols !== undefined ? detectedCols : 0,
      car: detectedCar === true,
      lang: detectedLang,
      zones: detectedZones,
      zorder: detectedZorder,
      fx: detectedFx,
      filter: detectedFilter === true ? true : detectedFilter === false ? false : undefined,
      list: normalized,
      // raw server cards + rev: the iframe renders these through WallCore.applySnapshot
      cards: activeCards || null,
      rev: typeof rev === 'number' ? rev : fetchedRev,
      editable,
      sprites,
    };
  }, [cards, fetchedCards, fetchedRev, rev, isOwner, resolvedAuth, content, wallId, slug, funnel, lang, cols, car, filter, zones, zorder, flying, sound, sprites, editable]);

  useEffect(() => {
    if (!payload || !iframeRef.current) return;
    const iframe = iframeRef.current;
    const wallKey = `${payload.id || wallId || 'default'}_${editable ? '1' : '0'}_${isOwner ? '1' : '0'}_${payload.lang || 'en'}_${autoHeight ? 'ah' : ''}${frameless ? 'fl' : ''}`;

    // If iframe is already mounted for this exact wall, avoid destructive srcdoc reloads!
    if (mountedWallKeyRef.current === wallKey && iframe.srcdoc) {
      if (internalUpdateRef.current) {
        internalUpdateRef.current = false;
        return;
      }
      /* Don't re-send a payload the frame already has (same cards, rev and settings). */
      let sig = '';
      try { sig = JSON.stringify(payload); } catch (e) {}
      if (sig && sig === lastPostedRef.current) return;
      lastPostedRef.current = sig;
      try {
        iframe.contentWindow?.postMessage({
          __ezsheetUpdatePayload: true,
          payload: payload
        }, '*');
      } catch (err) {}
      return;
    }

    mountedWallKeyRef.current = wallKey;
    heightDamperRef.current?.reset();
    internalUpdateRef.current = false;
    try { lastPostedRef.current = JSON.stringify(payload); } catch (e) { lastPostedRef.current = ''; }

    const json = JSON.stringify(payload)
      .replace(/</g, '\\u003c')
      .replace(/\u2028/g, '\\u2028')
      .replace(/\u2029/g, '\\u2029');

    const spriteInit = `window.WALL_SPRITES=${JSON.stringify(sprites || ['bee:0','bee:1']).replace(/</g, '\\u003c')};`;

    const parentOrigin =
      typeof window !== 'undefined' && window.location && window.location.origin && window.location.origin !== 'null' && !window.location.origin.startsWith('about:')
        ? window.location.origin
        : '';
    const resolvedDomains = (domains || []).map((d: any) => typeof d === 'string' ? d : (d?.domain || '')).filter(Boolean);
    const effectiveSyncUrl = syncUrl || parentOrigin;

    const doc = `<!DOCTYPE html>
<html lang="${payload.lang || 'en'}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>${title}</title>
<style>${WALL_CSS}${getEzSheetEditor().CSS}</style>
${autoHeight ? '<style>html{overflow:hidden;scrollbar-gutter:auto}</style>' : ''}${frameless ? '<style>html,body{background:transparent!important}</style>' : ''}
<script>
${ZDOG_SCRIPT}
if(typeof window !== 'undefined' && typeof Zdog === 'undefined' && window.Zdog){
  var Zdog = window.Zdog;
}
</script>
</head>
<body class="${editable ? 'edit' : 'ro'}${isOwner ? ' is-owner' : ' not-owner'}">
<div id="panel" class="import-panel hidden">
<div style="display:flex;justify-content:space-between"><b>V620.01 <span data-i18n="title">Wall editor</span> - <span id="idDisplay2">${payload.funnel || payload.slug || payload.id || '一起維基'}</span></b><button onclick="closePanel()" class="tool-btn" data-i18n-aria="close">✕</button></div>

<div class="toolbar">
<label class="tool-btn" style="background:#e3f2fd" onclick="var f=document.getElementById('fileUpload'); if(f && event.target!==f) f.click();"><input type="file" id="fileUpload" accept="image/*,video/*,audio/*,.pdf,.html,.htm,.json,.txt,application/pdf,text/html" multiple hidden>📥 <span data-i18n="upload">Upload</span></label>
<button class="tool-btn" id="btnDownloadJSON" style="background:#fff9c4" title="Download EzSheet (.txt, plain text)">📤 <span data-i18n="download">Download</span></button>
<button class="tool-btn" id="btnClearWall" style="background:#ff8a80" onclick="clearWall()">🗑️ <span data-i18n="clear">Clear</span></button>
</div>

<div class="toolbar" style="gap:10px">
<span style="font-size:10px;opacity:.6" data-i18n="zorderHint">Drag to set the order of the two top bands</span>
<div class="zdrag" id="zdrag">
  <div class="zchip" data-z="c" draggable="true"><span class="grip">⠿</span>InfoMercial</div>
  <div class="zchip" data-z="a" draggable="true"><span class="grip">⠿</span>Topic</div>
  <div class="zchip fixed" data-z="b"><span class="grip">▦</span>Content</div>
</div>
</div>
${getEzSheetEditor().html({ ids: true, i18n: true, btnClass: 'tool-btn', pushClass: 'tool-btn primary', top: '<div id="metaFields" class="meta-fields" hidden>\n<label><span data-i18n="fTitle">Title</span><input id="mTitle" maxlength="200" autocomplete="off"></label>\n<label><span data-i18n="fTags">Tags</span><input id="mTags" data-i18n-ph="tagsPh" autocomplete="off"></label>\n<label><span data-i18n="fLength">Length</span><input id="mDur" data-i18n-ph="lenPh" inputmode="numeric" autocomplete="off"></label>\n</div>\n', picsHTML: `  <div class="spr-box"><b data-i18n="sprTitle">Flying sprites 🐝</b><p class="hint" style="margin:0;font-size:11px;opacity:.7" data-i18n="sprHint">The two built-in bees are ready to swap</p><div id="sprStrip"></div></div>
  <p class="hint" style="margin:0;font-size:11px;opacity:.7" data-i18n="picHint">Images and 3D models (.glb .gltf .stl .obj) are inlined into the wall</p>
  <div class="drop" id="drop"><span data-i18n="picDrop">Drop images or 3D models here, or click to choose files</span><input type="file" id="picFile" accept="image/*,.glb,.gltf,.stl,.obj" multiple hidden></div>
  <div class="pics" id="pics"></div>
  <div class="toolbar" style="margin:0">
    <select id="picZone" class="tool-btn" style="padding:7px 10px">
      <option value="b" data-i18n="picZoneB">Content</option>
      <option value="a" data-i18n="picZoneA">Topic</option>
      <option value="c" data-i18n="picZoneC">InfoMercial</option>
    </select>
    <button class="tool-btn primary" id="btnPicAdd" data-i18n="picAdd">Add as cards</button>
    <button class="tool-btn" style="background:var(--yellow)" id="btnSprites" data-i18n="picSprites">Use as sprites</button>
    <button class="tool-btn" id="btnSpriteReset" data-i18n="spriteReset">Default bees</button>
  </div>
`, mediaHTML: `  <div style="display:flex;flex-direction:column;gap:12px;padding:4px 0 8px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin:2px 0 4px">
      <div style="display:flex;align-items:center;gap:6px">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
        <span style="font-weight:700;font-size:13px;color:#0f172a">Upload Media (Straight to Content)</span>
        <a href="javascript:void(0)" id="mediaSupportedLink" style="font-size:11.5px;color:#2563eb;text-decoration:underline;margin-left:4px">Supported types</a>
      </div>
      <span style="font-size:11.5px;color:#64748b;font-weight:500">Up to 100MB per file</span>
    </div>

    <div id="mediaSupportedBox" style="display:none;background:#f8fafc;border:1.5px solid #cbd5e1;border-radius:12px;padding:12px 14px;font-size:11.5px;line-height:1.6;color:#334155">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;font-weight:700;color:#0f172a">
        <span>Supported Formats & Behavior:</span>
        <button type="button" id="btnCloseSupported" style="border:none;background:none;cursor:pointer;font-weight:800;font-size:14px;color:#64748b">✕</button>
      </div>
      <ul style="margin:0;padding-left:18px">
        <li><b>Images (JPG, PNG, GIF, WEBP)</b>: Uploaded to server & added as &lt;img&gt;</li>
        <li><b>Video (MP4, WEBM, OGG)</b>: Uploaded to server & added as &lt;video controls&gt;</li>
        <li><b>Audio (MP3, WAV, OGG)</b>: Uploaded to server & added as &lt;audio controls&gt;</li>
        <li><b>PDF (.pdf)</b>: Uploaded to server & added as an &quot;Open PDF ↗&quot; link card</li>
        <li><b>HTML (.html, .htm)</b>: Inlined as a sandboxed card (not stored on server)</li>
      </ul>
      <div style="margin-top:6px;color:#b91c1c;font-size:11px">
        Note: SVG, Markdown, ZIP archives, Office docs, and executables/scripts are rejected.
      </div>
    </div>

    <div id="mediaSignIn" hidden style="border:1px dashed #cbd5e1;border-radius:10px;padding:18px;text-align:center;font-size:13px;color:#334155;background:#f8fafc">🔒 Sign in to upload media. <a href="/login" target="_top" rel="noopener" style="color:#0284c7;font-weight:700">Sign in</a></div>
    <div class="drop media-drop" id="mediaDrop">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 10px;display:block"><rect x="3" y="3" width="18" height="18" rx="3" ry="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
      <div style="font-size:13.5px;margin-bottom:6px">
        <span style="color:#0284c7;font-weight:700">Click to choose multiple files</span>
        <span style="color:#334155;font-weight:600"> or drag & drop</span>
      </div>
      <div style="font-size:11.5px;color:#475569;margin-bottom:4px;font-weight:500">
        Images • Video • Audio • PDF • HTML
      </div>
      <div style="font-size:11px;color:#94a3b8">Each finished upload is added straight to Content in the sheet; Push creates cards.</div>
      <input type="file" id="mediaFileInput" accept="image/*,video/*,audio/*,.pdf,.html,.htm,application/pdf,text/html" multiple hidden>
    </div>

    <div id="mediaUploadList" style="display:flex;flex-direction:column;gap:8px;max-height:280px;overflow-y:auto;padding:2px"></div>
  </div>` })}
</div>
<div id="wall" class="masonry"></div>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<script id="wall-data" type="application/json">${json}</script>
<script>${spriteInit}</script>
<script>${WALL_CORE_SCRIPT}</script>
<script>${EZSHEET_EDITOR_SCRIPT}</script>
<script>
window.auth = ${JSON.stringify(resolvedAuth)};
window.isOwner = ${JSON.stringify(!!isOwner)};
window.WALL_ORIGIN = ${JSON.stringify(parentOrigin)};
window.WALL_DOMAINS = ${JSON.stringify(resolvedDomains)};
window.WALL_ID = ${JSON.stringify(wallId)};
window.WALL_FUNNEL = ${JSON.stringify(payload.funnel || funnel || '')};
window.WALL_SLUG = ${JSON.stringify(payload.slug || slug || wallId || '')};
window.MESSAGE_ID = ${JSON.stringify(messageId || null)};
try{WallCore.setWallSync(${JSON.stringify(effectiveSyncUrl)});}catch(e){}
${frameCheckUrl ? `try{WallCore.setFrameCheck(${JSON.stringify(frameCheckUrl)});}catch(e){}` : ''}
${metaUrl ? `try{WallCore.setMetaFetch(${JSON.stringify(metaUrl)});}catch(e){}` : ''}
try { WallCore.bootReadonly(); } catch (e) { console.error('bootReadonly failed', e); }
window.EZ_OPEN_EDITOR = ${JSON.stringify(!!openEditor)};

var fw = document.querySelector('.fab-wrap');
if(fw && !document.getElementById('copyBtn')){
  fw.insertAdjacentHTML('beforeend',
    '<button id="copyBtn" class="pill exp" data-i18n-aria="copyWall" title="Copy Standalone HTML"><span class="mi">📋</span></button>'
   +'<button id="dlBtn" class="pill exp" data-i18n-aria="dlWall" title="Download Standalone HTML"><span class="mi">💾</span></button>');
}

/* ---------- Editor Functions & Interactions ---------- */
var $ = function(id){ return document.getElementById(id); };
var currentCols = ${payload?.cols !== undefined ? payload.cols : 0};
var currentCar = ${payload?.car === true ? 'true' : 'false'};
var currentZones = ${JSON.stringify(payload?.zones || { a: true, b: true, c: true })};
var currentZOrder = ${JSON.stringify(payload?.zorder || ['c', 'a', 'b'])};

function setCols(n){
  currentCols = n;
  if(WallCore.updateLayoutUI) WallCore.updateLayoutUI(n);
  if(typeof window.__wallPack === 'function') window.__wallPack();
  if(typeof WallCore.saveWallSetting === 'function'){
    var lVal = n === -1 ? 'original' : (n === 0 ? 'random' : String(n));
    WallCore.saveWallSetting({ layout: lVal });
  }
}
function setCar(on){
  currentCar = !!on;
  var wallEl = $('wall');
  if(wallEl && WallCore.setCarousel) WallCore.setCarousel(wallEl, currentCar);
  if(typeof window.__wallPack === 'function') window.__wallPack();
  if(typeof WallCore.saveWallSetting === 'function'){
    WallCore.saveWallSetting({ carousel: currentCar, carousal: currentCar });
  }
}
function paintChips(){
  var box = $('zdrag');if(!box) return;
  currentZOrder.filter(function(z){ return z !== 'b'; }).forEach(function(z, i){
    var el = box.querySelector('.zchip[data-z="' + z + '"]');if(el) el.style.order = String(i);
  });
  var bChip = box.querySelector('.zchip[data-z="b"]');if(bChip) bChip.style.order = '9';
}
function setOrder(arr){
  currentZOrder = WallCore.validOrder(arr);
  WallCore.applyZoneOrder(currentZOrder);
  paintChips();
}

/* separators, boundary tests and tag balance come from the shared editor (ezsheet-editor.js) */
var EZE=window.EzSheetEditor,SEP=EZE.SEP,ZSEP=EZE.ZSEP,CSEP=EZE.CSEP,isSep=EZE.isSep,isZone=EZE.isZone,isCZone=EZE.isCZone;
var ZMARK='\\u0000zone',CMARK='\\u0000czone',WIKIMARK='\\u0000wiki',COWIKIMARK='\\u0000cowiki';
var tagDepth=EZE.tagDepth,closed=EZE.closed;

function splitBlocks(text){
  var out=[];var buf=null;var md=null;
  var lines=String(text||'').split(/\\r?\\n/);
  for(var i=0;i<lines.length;i++){
    var rawLine=lines[i];
    var line=rawLine.trim();
    if(md!==null){
      md+='\\n'+rawLine;
      if(/<\\/md>\\s*$/i.test(line)){ out.push(md.trim()); md=null; }
      continue;
    }
    if(/^<wiki\\b/i.test(line)){
      if(buf!==null){ out.push(buf.trim()); buf=null; }
      out.push(WIKIMARK);
      continue;
    }
    if(/^<\\/wiki>/i.test(line)){
      if(buf!==null){ out.push(buf.trim()); buf=null; }
      continue;
    }
    if(/^<(?:co-wiki|cowiki)\\b/i.test(line)){
      if(buf!==null){ out.push(buf.trim()); buf=null; }
      out.push(COWIKIMARK);
      continue;
    }
    if(/^<\\/(?:co-wiki|cowiki)>/i.test(line)){
      if(buf!==null){ out.push(buf.trim()); buf=null; }
      continue;
    }
    if(buf===null && /^<md>/i.test(line)){
      if(/<\\/md>\\s*$/i.test(line)&&line.length>4) out.push(line);
      else md=rawLine;
      continue;
    }
    if(buf!==null){
      if(isSep(line)||isZone(line)||isCZone(line)||(/^https?:\\/\\/\\S+$/i.test(line)&&buf.lastIndexOf('<')<=buf.lastIndexOf('>'))){
        out.push(buf.trim());buf=null;
      } else {
        buf+='\\n'+rawLine;
        if(closed(buf)){ out.push(buf.trim());buf=null; }
        continue;
      }
    }
    if(isZone(line)){ out.push(ZMARK); continue; }
    if(isCZone(line)){ out.push(CMARK); continue; }
    if(!line||isSep(line)) continue;
    if(line.startsWith('<')){
      if(/^<script\\b/i.test(line)&&out.length&&out[out.length-1].startsWith('<')) buf=out.pop()+'\\n'+rawLine;
      else buf=rawLine;
      if(closed(buf)){ out.push(buf.trim()); buf=null; }
      continue;
    }
    var found=line.match(/https?:\\/\\/[^\\s"'<>]+/gi);
    if(found){ for(var f=0;f<found.length;f++) out.push(found[f]); }
    else out.push(line);
  }
  if(buf!==null) out.push(buf.trim());
  if(md!==null) out.push(md.trim());
  return out;
}

function parseBatch(text){
  text=String(text||'').trim();if(!text) return {items:[],skipped:0};
  if(/^[[\{]/.test(text) || /^".*"$/s.test(text)){
    try{
      var obj=JSON.parse(text);
      if(typeof obj === 'string'){
        return parseBatch(obj);
      }
      if(obj && typeof obj === 'object'){
        if(typeof obj.content === 'string' && !obj.list && !obj.cards && !obj.items){
          return parseBatch(obj.content);
        }
        if(typeof obj.raw === 'string' && !obj.list && !obj.cards && !obj.items){
          return parseBatch(obj.raw);
        }
        if(typeof obj.text === 'string' && !obj.list && !obj.cards && !obj.items){
          return parseBatch(obj.text);
        }
        var arr=Array.isArray(obj)?obj:(obj.list||obj.cards||obj.items||obj.wall_cards||obj.current||obj.data);
        if(Array.isArray(arr)){
          var items=arr.map(function(o){
            if(typeof o === 'string'){
              var sub = parseBatch(o);
              return (sub && sub.items && sub.items[0]) || WallCore.normalize({ _raw: o, raw: o, url: o, type: 'wiki', zone: 'b' });
            }
            return WallCore.normalize(o&&typeof o==='object'?Object.assign({},o,{_id:null}):null);
          }).filter(Boolean);
          return {
            items: items,
            skipped: arr.length - items.length,
            cols: WallCore.validCols(obj.cols) ? obj.cols : undefined,
            car: typeof obj.car === 'boolean' ? obj.car : undefined,
            zones: obj.zones,
            zorder: obj.zorder,
            id: obj.id || obj.wall_id || obj.slug
          };
        }
      }
    }catch(e){}
  }
  var blocks=splitBlocks(text),items=[];var skipped=0;
  var buf=[];
  var currentTag='wiki';
  var flush=function(zone){
    buf.forEach(function(o){
      o.zone=zone;
      if(currentTag==='cowiki'){
        o.guest=true;
      } else {
        o.guest=false;
      }
      items.push(o);
    });
    buf=[];
  };
  blocks.forEach(function(b){
    if(b===WIKIMARK){ currentTag='wiki'; return; }
    if(b===COWIKIMARK){ currentTag='cowiki'; return; }
    if(b===CMARK){ flush('c'); return; }
    if(b===ZMARK){ flush('a'); return; }
    var it=WallCore.parseOne(b);
    if(it) buf.push(it); else skipped++;
  });
  flush('b');
  return {items:items,skipped:skipped};
}

var toastT;
function toast(m){
  var el=$('toast');if(!el) return;
  try{ var v=WallCore.ezVisibleView(window); el.style.bottom=(Math.max(0,innerHeight-v.b)+24)+'px'; }catch(e){}
  el.textContent=m;el.classList.add('show');
  clearTimeout(toastT);toastT=setTimeout(function(){ el.classList.remove('show'); }, String(m).length > 40 ? 6000 : 2800);
}

function updateCount(){
  var list=window.__wallList||[];
  var cnt=$('count');if(cnt) cnt.textContent=WallCore.t('count',{n:list.length});
  document.body.classList.toggle('empty-wall', !list.length);
}

function render(){
  var list = window.__wallList || [];
  var A = list.filter(function(o){ return o.zone === 'a'; });
  var B = list.filter(function(o){ return o.zone === 'b' || !o.zone; });
  var C = list.filter(function(o){ return o.zone === 'c'; });
  if(typeof WallCore.zoneAUpdate === 'function') WallCore.zoneAUpdate(A);
  if(typeof WallCore.zoneCUpdate === 'function') WallCore.zoneCUpdate(C);
  var keep = new Set(B.map(function(o){ return o._id; }));
  var wallEl = $('wall');
  if(wallEl){
    Array.prototype.slice.call(wallEl.querySelectorAll('.item')).forEach(function(el){
      if(el.dataset && el.dataset.id && !keep.has(el.dataset.id)){
        WallCore.dropCard(el);
      }
    });
    var have = new Map();
    Array.prototype.slice.call(wallEl.querySelectorAll('.item')).forEach(function(el){
      if(el.dataset && el.dataset.id) have.set(el.dataset.id, el);
    });
    B.forEach(function(o){
      var el = have.get(o._id);
      if(!el){
        el = WallCore.makeCard(o, false);
        wallEl.appendChild(el);
      }
      WallCore.applySize(el, o);
    });
    var em = wallEl.querySelector('.empty');
    if(!B.length && !A.length && !C.length){
      if(!em){
        em = document.createElement('div');
        em.className = 'empty';
        em.textContent = WallCore.t('wallEmpty');
        wallEl.appendChild(em);
      }
    } else {
      if(em) em.remove();
    }
  }
  updateCount();
  if(typeof window.__wallPack === 'function') window.__wallPack();
}

/* notifyParent() removed in pass 3: it rebuilt <wiki>/<co-wiki> text from the
   local list and pushed it (zones and identity lost). Pushes go through sendPush(). */

function gotoZoneLine(){
  var ta=$('input');if(!ta) return;
  var lines=ta.value.split('\\n');
  var idx=-1,pos=0,at=0;
  for(var k=0;k<lines.length;k++){
    if(isZone(lines[k])){ idx=k;at=pos;break; }
    pos+=lines[k].length+1;
  }
  if(idx<0) return;
  ta.setSelectionRange(at,at+lines[idx].length);
  var cs=getComputedStyle(ta);
  var lh=parseFloat(cs.lineHeight)||20;
  ta.scrollTop=Math.max(0,Math.round(idx*lh-ta.clientHeight/2+lh));
}

/* the editor (scroll bar, filter, keys) is the shared EzSheetEditor, mounted below */
var EZED=null;
function taSync(){ if(EZED) EZED.sync(); }

function showTab(which){
  var isMedia = which === 'media' || which === 'tabMedia' || which === 2;
  var isPics = which === 'pics' || which === 'tabPics' || which === 1 || which === true;
  var isSheet = !isMedia && !isPics;
  if($('paneSheet')) $('paneSheet').hidden = !isSheet;
  if($('taFind')) $('taFind').hidden = !isSheet;
  if($('panePics')) $('panePics').hidden = !isPics;
  if($('paneMedia')) $('paneMedia').hidden = !isMedia;
  if($('tabSheet')) $('tabSheet').setAttribute('aria-selected', String(isSheet));
  if($('tabPics')) $('tabPics').setAttribute('aria-selected', String(isPics));
  if($('tabMedia')) $('tabMedia').setAttribute('aria-selected', String(isMedia));
  if(isSheet) requestAnimationFrame(taSync);
  else if(isPics && typeof paintPics === 'function') paintPics();
  else if(isMedia && typeof paintMedia === 'function') paintMedia();
}

/* ---------- EzSheet <-> server (pass 3) ----------
   SHEET.plain : what the textarea shows (never has tags)
   SHEET.mem   : [{cid, raw, zone}] remembered from the last server sheet; re-attached
                 as hidden <!-- cid:... --> lines only in the pushed text. */
var SHEET = { plain: null, mem: [], tagged: '' };
function ezApiBase(){
  var o = String(window.WALL_ORIGIN || '').replace(/\\/+$/, '');
  return o + '/api/wall/' + encodeURIComponent(window.WALL_ID || 'ezsheet');
}
function ezHeaders(){
  var h = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  try { var t = WallCore.ownerToken ? WallCore.ownerToken() : ''; if(t) h['x-wall-owner'] = t; } catch(e){}
  try { if (WallCore.csrfHeaders) Object.assign(h, WallCore.csrfHeaders()); } catch(e){}
  return h;
}
function setSheet(tagged){
  var m = WallCore.ezSheetMemory(tagged || '');
  SHEET.plain = m.plain; SHEET.mem = m.mem; SHEET.tagged = String(tagged || '');
  var ta = $('input'); if(ta){ ta.value = m.plain; delete ta.dataset.dirty; }
}
function loadSheet(){
  if(!window.isOwner) return Promise.resolve(false);
  return fetch(ezApiBase() + '/sheet', { headers: ezHeaders(), credentials: 'include' })
    .then(function(r){ return r.ok ? r.json() : null; })
    .then(function(d){ if(d && typeof d.sheet === 'string'){ var ta=$('input'); if(!ta || !ta.dataset.dirty) setSheet(d.sheet); else { SHEET.mem = WallCore.ezSheetMemory(d.sheet).mem; } return true; } return false; })
    .catch(function(){ return false; });
}
/* Fallback before the sheet has loaded: rebuild from the mothers on the wall. */
function sheetFromWall(){
  var list = (window.__wallList || []).filter(function(o){ return o.role !== 'child' && !o.guest; });
  var j = function(a){ return a.map(function(o){ return (o._raw || '').trim(); }).join('\\n' + SEP + '\\n'); };
  var A = list.filter(function(o){ return o.zone === 'a'; }), C = list.filter(function(o){ return o.zone === 'c'; }), B = list.filter(function(o){ return o.zone !== 'a' && o.zone !== 'c'; });
  var parts = [];
  if(A.length) parts.push(j(A) + '\\n' + ZSEP);
  if(C.length) parts.push(j(C) + '\\n' + CSEP);
  if(B.length) parts.push(j(B));
  return parts.join('\\n') + (parts.length ? '\\n' : '');
}
window.openPanel = function(i){
  var mf=$('metaFields');if(mf) mf.hidden=true;
  var ta=$('input');if(!ta) return;
  if(!ta.dataset.dirty) ta.value = SHEET.plain !== null ? SHEET.plain : sheetFromWall();
  delete ta.dataset.editIndex;
  updateCount();
  var panel=$('panel');
  if(panel){
    panel.classList.remove('hidden');
    ta.focus();
    showTab(false);
    /* ✎ on a card: select that card's text in the sheet */
    var target = null, list = window.__wallList || [];
    if(i && i.cid) target = list.find(function(o){ return o.cid === i.cid; });
    else if(Number.isInteger(i)) target = list[i];
    var at = target && target._raw ? ta.value.indexOf(String(target._raw).trim()) : -1;
    if(at >= 0){
      ta.setSelectionRange(at, at + String(target._raw).trim().length);
      var lh = parseFloat(getComputedStyle(ta).lineHeight) || 20;
      ta.scrollTop = Math.max(0, Math.round(ta.value.slice(0, at).split('\\n').length * lh - ta.clientHeight / 2));
    } else {
      var end=ta.value.length;ta.setSelectionRange(end,end);ta.scrollTop=ta.scrollHeight;
      if(!Number.isInteger(i) && !(i && i.cid)) gotoZoneLine();
    }
    requestAnimationFrame(taSync);
  }
  loadSheet();
};

/* item 24: the editor panel, toasts and dialogs open inside the VISIBLE part of the (very tall) wall frame */
(function(){
  var p=$('panel');if(!p||!window.MutationObserver||!WallCore.ezAnchorPopover) return;
  var h=null;
  function sync(){ if(!p.classList.contains('hidden')){ if(!h) h=WallCore.ezAnchorPopover(p,{mode:'top',top:68,dismiss:false,sheet:false}); else h.fit(); } else if(h){ h.close(true); h=null; } }
  new MutationObserver(sync).observe(p,{attributes:true,attributeFilter:['class']}); sync();
})();
window.closePanel = function(){
  var panel=$('panel');if(panel) panel.classList.add('hidden');
};

function ezDialog(title, lines, buttons){
  var old = document.querySelector('.ez-dlg'); if(old) old.remove();
  var box = document.createElement('div'); box.className = 'ez-dlg';
  var html = '<div class="box" role="dialog" aria-modal="true"><b>' + WallCore.esc(title) + '</b>';
  if(lines && lines.length) html += '<ul>' + lines.map(function(l){ return '<li>' + WallCore.esc(l) + '</li>'; }).join('') + '</ul>';
  html += '<div class="row"></div></div>';
  box.innerHTML = html;
  var row = box.querySelector('.row');
  (buttons || [{ label: 'OK' }]).forEach(function(b){
    var el = document.createElement('button'); el.className = 'tool-btn' + (b.primary ? ' primary' : ''); el.textContent = b.label;
    el.addEventListener('click', function(){ box.remove(); if(b.run) b.run(); });
    row.appendChild(el);
  });
  document.body.appendChild(box);
  /* item 24: true modal (a decision is needed) - keeps its backdrop, but the box sits in the visible area */
  try{ WallCore.ezAnchorPopover(box.querySelector('.box'),{mode:'center',dismiss:false,sheet:false}); }catch(e){}
  return box;
}
var pushSeq = 0, pushWait = {};
/* Send one push. Embedded: the parent page (logged-in owner session) posts it and
   answers with __ezsheetPushResult. Standalone: fetch directly. */
/* item 28: one push at a time; the button is greyed out with a spinner while waiting. */
var pushBusy = false;
function pushBtnBusy(on){
  var btn = $('btnPush'); if(!btn) return;
  btn.disabled = !!on; btn.classList.toggle('busy', !!on);
  if(on) btn.setAttribute('aria-busy', 'true'); else btn.removeAttribute('aria-busy');
}
function sendPush(body){
  if(pushBusy){ toast(WallCore.t('pushBusy') !== 'pushBusy' ? WallCore.t('pushBusy') : 'A push is already running…'); return; }
  pushBusy = true; pushBtnBusy(true);
  var done = function(status, data){ pushBusy = false; pushBtnBusy(false); onPushResult(status, data || {}, body); };
  if(window.parent && window.parent !== window){
    var rid = 'p' + (++pushSeq) + '_' + Date.now();
    pushWait[rid] = done;
    /* the parent aborts after 30 s and always answers; this is only a safety net */
    setTimeout(function(){ if(pushWait[rid]){ delete pushWait[rid]; done(0, { error: 'timeout' }); } }, 35000);
    try{
      window.parent.postMessage({
        __ezsheetPushToWall: true, requestId: rid,
        __ezsheetRaw: body.raw, raw: body.raw,
        deleteCids: body.delete_cids || [], confirmEmpty: !!body.confirm_empty,
        addCowikiRaws: body.add_cowiki_raws || [],
        wallId: window.WALL_ID || 'ezsheet', slug: window.WALL_SLUG || window.WALL_ID, messageId: window.MESSAGE_ID || null,
        lang: (WallCore.getLang && WallCore.getLang()) || ''
      }, '*');
    }catch(e){ delete pushWait[rid]; done(0, { error: 'postMessage' }); }
    return;
  }
  var ctl = (typeof AbortController === 'function') ? new AbortController() : null;
  var tm = setTimeout(function(){ if(ctl) ctl.abort(); }, 30000);
  fetch(ezApiBase() + '/push-to-wall', { method: 'POST', headers: ezHeaders(), credentials: 'include', signal: ctl ? ctl.signal : undefined,
    body: JSON.stringify({ wall_id: window.WALL_ID, raw: body.raw, delete_cids: body.delete_cids || [], confirm_empty: body.confirm_empty ? 1 : 0, add_cowiki_raws: body.add_cowiki_raws || [] }) })
    .then(function(r){ clearTimeout(tm); return r.json().catch(function(){ return {}; }).then(function(j){ done(r.status, j); }); })
    .catch(function(){ clearTimeout(tm); done(0, { error: 'timeout' }); });
}
function onPushResult(status, data, body){
  if(status >= 200 && status < 300 && data && Array.isArray(data.cards)){
    if(typeof data.sheet === 'string') setSheet(data.sheet);
    WallCore.applySnapshot(data.cards, data.rev);
    updateCount();
    var warn = data.unmatched_mothers_with_children || [];
    var showWarn = function(){
      ezDialog('These WiKi cards have Co-WiKi cards but are not in your sheet. They were kept.', warn.map(function(w){ return w.raw + '  (' + w.children + ' Co-WiKi)'; }), [
        { label: 'Keep them' },
        { label: 'Delete them and their Co-WiKi cards', primary: true, run: function(){
          var ids = warn.map(function(w){ return String(w.cid); });
          sendPush({ raw: WallCore.ezRemoveCids(SHEET.tagged, ids), delete_cids: ids });
        } }
      ]);
    };
    /* Item 11 (EZ-REL-20261014-1): blocks the server did not turn into WiKi cards are always shown. */
    var na = Array.isArray(data.not_added) ? data.not_added : [];
    if(na.length){
      var addable = na.filter(function(n){ return n.reason === 'same_as_cowiki_card'; }).map(function(n){ return n.raw; });
      var btns = [{ label: addable.length ? 'Leave them as Co-WiKi only' : 'OK', run: function(){ if(warn.length) showWarn(); } }];
      if(addable.length) btns.push({ label: 'Add as WiKi card' + (addable.length > 1 ? 's' : ''), primary: true, run: function(){
        sendPush({ raw: body.raw, delete_cids: body.delete_cids || [], add_cowiki_raws: addable });
      } });
      ezDialog((na.length === 1 ? 'This card was' : 'These ' + na.length + ' cards were') + ' not added: the same link is already a Co-WiKi card on this wall.',
        na.map(function(n){ return n.raw + (n.mother_raw ? '  (Co-WiKi under: ' + n.mother_raw + ')' : '') + (n.reason === 'is_cowiki_card' ? '  [tagged as that Co-WiKi card]' : ''); }), btns);
    } else if(warn.length){ showWarn(); } else {
      closePanel();
    }
    var st = data.stats || {};
    toast('✓ ' + (WallCore.t('loaded', { n: (data.cards || []).filter(function(c){ return c.role !== 'child'; }).length }) || 'Pushed')
      + (typeof st.created === 'number' ? ' · +' + st.created + ' new' + (st.updated ? ', ' + st.updated + ' changed' : '') + (st.deleted ? ', ' + st.deleted + ' removed' : '') : '')
      + (na.length ? ' · ' + na.length + ' not added' : ''));
    return;
  }
  var err = data && data.error;
  if(status === 422 && err === 'mother_zone_locked'){
    ezDialog(data.message || 'WiKi cards that have Co-WiKi cards must stay in the Content zone. Nothing was saved.',
      (data.cards || []).map(function(c){ return c.raw + '  → ' + (c.to_zone === 'a' ? 'Topic' : 'InfoMercial') + '  (' + c.children + ' Co-WiKi)'; }));
    return;
  }
  if(status === 422 && err === 'empty_sheet'){
    if(confirm(data.message || 'The sheet is empty. Delete every WiKi card and its Co-WiKi cards?')) sendPush({ raw: '', confirm_empty: 1 });
    return;
  }
  if(status === 403){ toast('Only the wall owner can push to this wall.'); return; }
  /* item 28: plain words for the common failures (the parent page shows the same, in the UI language) */
  if(status === 419){ toast('Session expired. Please refresh the page and push again.'); return; }
  if(status === 409 && err === 'busy'){ toast('A push is already running. Please wait.'); return; }
  if(status === 0){ toast("Server didn't answer. Nothing may have been saved. Please try again."); return; }
  toast('Push failed' + (status ? ' (' + status + ')' : '') + (data && data.message ? ': ' + data.message : ''));
}
window.batchImport = function(){
  var ta=$('input');if(!ta) return;
  if(typeof flushStagedMedia === 'function') flushStagedMedia();
  sendPush({ raw: WallCore.ezRetag(ta.value, SHEET.mem) });
};

function exportItem(o){
  var r = { _raw: o._raw, type: o.type, w: o.w, h: o.h };
  if(o.mh) r.mh = o.mh;
  if(o.manual) r.manual = true;
  if(o.zone === 'a' || o.zone === 'c') r.zone = o.zone;
  if(o.span){ r.span = o.span; r.rh = o.rh; }
  if(o.emb) r.emb = o.emb;
  if(o.blk) r.blk = true;
  if(o.pic) r.pic = true;
  ['at','pub','title','tags','dur','guest','ok','cid','status','position','anchor','origin','user_id'].forEach(function(k){
    if(o[k] !== undefined) r[k] = o[k];
  });
  if(o.type === 'youtube'){ r.id = o.id; r.url = o.url; }
  else if(o.type === 'model'){ r.mdl = o.mdl; }
  else if(o.type === 'html'){ r.html = o.html; }
  else if(o.type === 'md'){}
  else { r.url = o.url; }
  return r;
}

function downloadText(text, type, name){
  if(window.parent && window.parent !== window){
    try { window.parent.postMessage({ __ezsheetDownload: true, text: text, type: type, name: name }, '*'); return; } catch(e){}
  }
  try {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text], { type: type }));
    a.download = name;
    document.body.appendChild(a);
    a.click();
    setTimeout(function(){
      a.remove();
      URL.revokeObjectURL(a.href);
    }, 1500);
  } catch(e){}
}
/* EzSheet download is ALWAYS plain text without cid tags. */
function downloadSheet(){
  var ta = $('input');
  var text = (ta && ta.value.trim()) ? ta.value : (SHEET.plain !== null ? SHEET.plain : sheetFromWall());
  downloadText(WallCore.ezStripTags(text), 'text/plain', (window.WALL_ID || 'ezsheet') + '.txt');
}

window.downloadJSON = function(){
  var list = window.__wallList || [];
  var text = JSON.stringify({
    id: window.WALL_ID || 'Wall',
    count: list.length,
    cols: currentCols,
    car: currentCar,
    zones: currentZones,
    zorder: currentZOrder,
    list: list.map(exportItem)
  }, null, 2);
  downloadText(text, 'application/json', (window.WALL_ID || 'wall') + '-random.json');
};
function downloadJSON(){ if(typeof window.downloadJSON === 'function') window.downloadJSON(); }

function buildStandalone(){
  var list = window.__wallList || [];
  var css = ${JSON.stringify(WALL_CSS)};
  var core = ${JSON.stringify(WALL_CORE_SCRIPT)};
  var data = JSON.stringify({
    id: window.WALL_ID || 'Wall',
    count: list.length,
    cols: currentCols,
    car: currentCar,
    zones: currentZones,
    zorder: currentZOrder,
    lang: WallCore.getLang(),
    fx: WallCore.fxState ? WallCore.fxState() : { fly: true, snd: true },
    filter: true,
    list: list.map(exportItem)
  }).replace(/</g, '\\u003c').replace(/\\u2028/g, '\\u2028').replace(/\\u2029/g, '\\u2029');

  return '<!DOCTYPE html>\\n<html lang=\"' + WallCore.getLang() + '\"><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1.0\"><title>'
    + WallCore.esc(window.WALL_ID || 'Wall') + '</title>'
    + '<style>' + css + '</style></head><body class=\"ro\"><div id=\"wall\" class=\"masonry\"></div>'
    + '<script id=\"wall-data\" type=\"application/json\">' + data + '<\\/script>'
    + '<script>window.WALL_SPRITES=' + JSON.stringify(WallCore.validSprites(window.WALL_SPRITES) || ['bee:0','bee:1']).replace(/</g, '\\u003c') + ';<\\/script>'
    + '<script>' + core + '<\\/script><script>WallCore.bootReadonly();<\\/script></body></html>';
}

function legacyCopy(text){
  var t = document.createElement('textarea');
  t.value = text;
  t.setAttribute('readonly', '');
  t.style.cssText = 'position:fixed;left:-9999px;top:0';
  document.body.appendChild(t);
  t.select();
  var ok = false;
  try { ok = document.execCommand('copy'); } catch(e){}
  t.remove();
  return ok;
}

function copyFullHTML(){
  var list = window.__wallList || [];
  if(!list.length){ toast(WallCore.t('emptyWall')); return; }
  var html = buildStandalone();
  if(navigator.clipboard && navigator.clipboard.writeText){
    navigator.clipboard.writeText(html).then(function(){
      toast(WallCore.t('copied', { n: list.length }));
    }).catch(function(){
      if(legacyCopy(html)){ toast(WallCore.t('copied', { n: list.length })); }
      else { downloadText(html, 'text/html', (window.WALL_ID || 'wall') + '-wall.html'); toast(WallCore.t('clipFallback')); }
    });
  } else {
    if(legacyCopy(html)){ toast(WallCore.t('copied', { n: list.length })); }
    else { downloadText(html, 'text/html', (window.WALL_ID || 'wall') + '-wall.html'); toast(WallCore.t('clipFallback')); }
  }
}

function downloadStandalone(){
  var list = window.__wallList || [];
  if(!list.length){ toast(WallCore.t('emptyWall')); return; }
  downloadText(buildStandalone(), 'text/html', (window.WALL_ID || 'wall') + '-wall.html');
  toast(WallCore.t('downloaded', { n: list.length }));
}

window.clearWall = function(){
  if(!confirm(WallCore.t('confirmClear'))) return;
  var ta = $('input'); if(ta){ ta.value = ''; ta.dataset.dirty = '1'; }
  sendPush({ raw: '', confirm_empty: 1 });
};
function clearWall(){ if(typeof window.clearWall === 'function') window.clearWall(); }


/* Put card text into the sheet in the right zone (a boundary labels the text ABOVE it). */
function sheetAppend(text, raw, zone){
  text = String(text || '').replace(/\\s+$/, '');
  var mark = zone === 'a' ? /^={3,}/ : (zone === 'c' ? /^#{3,}/ : null);
  if(!mark) return text ? (text + '\\n' + SEP + '\\n' + raw + '\\n') : (raw + '\\n');
  var lines = text.split('\\n');
  for(var k = 0; k < lines.length; k++){
    if(mark.test(lines[k].trim())){
      var above = k > 0 && lines[k-1].trim() !== '' && !isZone(lines[k-1]) && !isCZone(lines[k-1]);
      lines.splice(k, 0, (above ? SEP + '\\n' : '') + raw);
      return lines.join('\\n') + '\\n';
    }
  }
  return raw + '\\n' + (zone === 'a' ? ZSEP : CSEP) + '\\n' + text + (text ? '\\n' : '');
}
function addToSheet(raws, zone, stayOnCurrentTab){
  var ta = $('input'); if(!ta) return;
  var t = ta.dataset.dirty ? ta.value : (SHEET.plain !== null ? SHEET.plain : sheetFromWall());
  raws.forEach(function(r){ t = sheetAppend(t, r, zone || 'b'); });
  ta.value = t; ta.dataset.dirty = '1';
  updateCount();
  if(typeof taSync === 'function') taSync();
  var panel = $('panel');
  if(panel && panel.classList.contains('hidden')){
    panel.classList.remove('hidden');
    if(!stayOnCurrentTab) showTab(false);
  } else if(!stayOnCurrentTab){
    showTab(false);
  }
}
/* ---------- 上傳：圖片、PDF、HTML、影音、JSON 儲存到 ezsheet 資料夾並呈現預覽 ---------- */
if($('fileUpload')){
  $('fileUpload').addEventListener('change', async function(e){
    var files = Array.from(e.target.files || []); if(!files.length) return;
    this.value = '';
    var addedCards = [];
    for(var idx = 0; idx < files.length; idx++){
      var file = files[idx];
      var name = file.name;
      var ext = (name.split('.').pop() || '').toLowerCase();
      var type = file.type || '';

      if(ext === 'txt' || type === 'text/plain'){
        /* A downloaded EzSheet: load it into the editor (tags stripped). Nothing is
           saved until Push. Remembered cids still match identical text. */
        var sheetTxt = await new Promise(function(resolve, reject){ var r = new FileReader(); r.onload = function(){ resolve(String(r.result)); }; r.onerror = reject; r.readAsText(file); });
        var taT = $('input'); if(taT){ taT.value = WallCore.ezStripTags(sheetTxt); taT.dataset.dirty = '1'; }
        window.openPanel();
        toast('EzSheet loaded. Press Push to save it to the wall.');
        continue;
      }
      if(ext === 'json' || (type === 'application/json' && !type.startsWith('video/') && !type.startsWith('audio/'))){
        try{
          var txt = await new Promise(function(resolve, reject){
            var r = new FileReader();
            r.onload = function(){ resolve(String(r.result)); };
            r.onerror = reject;
            r.readAsText(file);
          });
          var m = txt.match(/<script id="wall-data"[^>]*>([\\s\\S]*?)<\\/script>/i); if(m) txt = m[1];
          var res = parseBatch(txt), items = res.items, skipped = res.skipped;
          if(items.length){
            /* Old JSON export: its WiKi cards go into the sheet; Co-WiKi cards are not
               part of a sheet. Nothing is saved until Push. */
            var taJ = $('input');
            if(taJ){
              var jt = '';
              ['a','c','b'].forEach(function(z){
                var raws = items.filter(function(c){ return !c.guest && (c.zone || 'b') === z; }).map(function(c){ return (c._raw || '').trim(); }).filter(Boolean);
                raws.forEach(function(r){ jt = sheetAppend(jt, r, z); });
              });
              taJ.value = jt; taJ.dataset.dirty = '1';
            }
            if(res.cols !== undefined) setCols(res.cols);
            if(res.car !== undefined) setCar(res.car);
            if(res.zones){ currentZones = WallCore.validZones(res.zones); if(WallCore.zonePaint) WallCore.zonePaint(currentZones); }
            if(res.zorder){ setOrder(res.zorder); }
            window.openPanel();
            toast('Loaded ' + items.length + ' cards into EzSheet. Press Push to save them.');
            continue;
          }
        }catch(err){
          console.error('[Wall Upload] JSON error:', err);
        }
      }

      var fileUrl = '', upDenied = false;
      var isHtmlFile = (ext === 'html' || ext === 'htm' || type === 'text/html');
      if(!isHtmlFile && window.EZ_AUTHED === false){ upDenied = true; }
      if(!isHtmlFile && !upDenied) try{
        var base64Data = await new Promise(function(resolve, reject){
          var reader = new FileReader();
          reader.onload = function(){ resolve(String(reader.result)); };
          reader.onerror = reject;
          reader.readAsDataURL(file);
        });
        var uploadRes = await fetch('/api/wall/upload-ezsheet-file', {
          method: 'POST',
          credentials: 'same-origin',
          headers: Object.assign({ 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, WallCore.csrfHeaders ? WallCore.csrfHeaders() : {}),
          body: JSON.stringify({ filename: name, data: base64Data, mime_type: type })
        });
        if(uploadRes.status === 401){ upDenied = true; window.EZ_AUTHED = false; ezShowSignIn(); }
        if(uploadRes.ok){
          var resData = await uploadRes.json();
          if(resData && resData.url) fileUrl = resData.url;
        } else if(!upDenied){
          try{ var errD = await uploadRes.json(); if(errD && (errD.message || errD.error)) toast('✗ ' + name + ': ' + (errD.message || errD.error)); }catch(e2){}
        }
      }catch(upErr){}

      /* hotfix: only HTML (inlined, never stored) and small images may be inlined */
      if(!fileUrl && !isHtmlFile && !(type.indexOf('image/') === 0 && file.size <= 2 * 1024 * 1024)){
        toast(upDenied ? '🔒 Sign in to upload ' + name : '✗ ' + name + ' was not uploaded');
        continue;
      }
      if(!fileUrl){
        try{
          fileUrl = await new Promise(function(resolve, reject){
            var reader = new FileReader();
            reader.onload = function(){ resolve(String(reader.result)); };
            reader.onerror = reject;
            reader.readAsDataURL(file);
          });
        }catch(err){ fileUrl = URL.createObjectURL(file); }
      }

      var htmlPreview = '', cardW = 340, cardH = 260, isPic = false;
      if(type.startsWith('image/') || ['png','jpg','jpeg','gif','webp','svg','bmp','ico'].includes(ext)){
        isPic = true; cardW = 340; cardH = 260;
        htmlPreview = '<div style="margin:0;background:#fff;display:flex;align-items:center;justify-content:center;min-height:160px;overflow:hidden;border-radius:8px"><img src="' + fileUrl + '" alt="' + WallCore.esc(name) + '" style="display:block;width:100%;height:auto;max-height:100%;object-fit:contain" /></div>';
      } else if(type === 'application/pdf' || ext === 'pdf'){
        cardW = 380; cardH = 340;
        htmlPreview = '<div style="margin:0;background:#f8fafc;width:100%;height:100%;display:flex;flex-direction:column;overflow:hidden;border-radius:8px"><div style="background:#1e293b;color:#fff;padding:8px 12px;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:space-between"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:75%">📄 ' + WallCore.esc(name) + '</span><a href="' + fileUrl + '" target="_blank" rel="noopener noreferrer" style="color:#38bdf8;text-decoration:none;font-size:11px;font-weight:700">Open ↗</a></div><iframe src="' + fileUrl + '#toolbar=0" style="flex:1;width:100%;min-height:280px;border:none;background:#fff" title="' + WallCore.esc(name) + '"></iframe></div>';
      } else if(type === 'text/html' || ext === 'html' || ext === 'htm'){
        cardW = 420; cardH = 320;
        htmlPreview = '<div style="margin:0;background:#fff;width:100%;height:100%;overflow:hidden;position:relative;border-radius:8px"><div style="background:#334155;color:#fff;padding:6px 10px;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:space-between"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:80%">🌐 ' + WallCore.esc(name) + '</span><a href="' + fileUrl + '" target="_blank" rel="noopener noreferrer" style="color:#67e8f9;text-decoration:none;font-size:10px">Open ↗</a></div><iframe src="' + fileUrl + '" style="width:100%;height:calc(100% - 28px);min-height:260px;border:none" sandbox="allow-scripts allow-same-origin allow-forms" title="' + WallCore.esc(name) + '"></iframe></div>';
      } else if(type.startsWith('video/') || ['mp4','webm','ogg','mov','m4v'].includes(ext)){
        cardW = 360; cardH = 280;
        htmlPreview = '<div style="margin:0;background:#000;width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;overflow:hidden;border-radius:8px"><video src="' + fileUrl + '" controls playsinline style="width:100%;height:auto;max-height:calc(100% - 26px);background:#000" preload="metadata"></video><div style="width:100%;background:#09090b;color:#a1a1aa;padding:4px 8px;font-size:11px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">🎬 ' + WallCore.esc(name) + '</div></div>';
      } else if(type.startsWith('audio/') || ['mp3','wav','ogg','m4a','aac','flac'].includes(ext)){
        cardW = 340; cardH = 180;
        htmlPreview = '<div style="margin:0;background:linear-gradient(135deg,#0f172a,#1e1b4b);color:#fff;width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;border-radius:8px"><div style="font-size:32px;margin-bottom:6px">🎵</div><div style="font-size:12px;font-weight:700;color:#e2e8f0;margin-bottom:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:90%">' + WallCore.esc(name) + '</div><audio src="' + fileUrl + '" controls style="width:100%;max-width:280px" preload="metadata"></audio></div>';
      } else {
        cardW = 340; cardH = 200;
        htmlPreview = '<div style="margin:0;background:#f1f5f9;color:#334155;width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;border-radius:8px"><div style="font-size:28px;margin-bottom:6px">📁</div><div style="font-size:12px;font-weight:700;margin-bottom:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:90%">' + WallCore.esc(name) + '</div><a href="' + fileUrl + '" download="' + WallCore.esc(name) + '" style="font-size:11px;color:#2563eb;text-decoration:none;font-weight:600">Download File ⬇</a></div>';
      }

      var newCard = WallCore.normalize({
        _raw: htmlPreview,
        type: 'html',
        html: htmlPreview,
        zone: 'b',
        w: cardW,
        h: cardH,
        pic: isPic,
        title: name,
        at: Date.now()
      });
      if(newCard) addedCards.push(newCard);
    }

    if(addedCards.length){
      addToSheet(addedCards.map(function(c){ return c._raw; }), 'b');
      toast('Uploaded ' + addedCards.length + ' file(s). Press Push to put them on the wall.');
    }
  });
}

/* Images & 3D Tab Handling */
var pics = [];
function paintPics(){
  var box = $('pics');if(!box) return;
  box.innerHTML = pics.length ? pics.map(function(p, i){
    var head = p.kind === 'model'
      ? '<div class="m3d"><b>◈</b><span>' + WallCore.esc(p.ext.toUpperCase()) + '</span><i>' + WallCore.esc(p.name) + '</i></div>'
      : '<img src="' + p.data + '" alt="">';
    var pick = p.kind === 'model'
      ? '<select disabled><option>' + WallCore.esc(WallCore.t('asCard')) + '</option></select>'
      : '<select data-i="' + i + '">'
        + '<option value="card"' + (p.use === 'card' ? ' selected' : '') + '>' + WallCore.esc(WallCore.t('asCard')) + '</option>'
        + '<option value="sprite"' + (p.use === 'sprite' ? ' selected' : '') + '>' + WallCore.esc(WallCore.t('asSprite')) + '</option>'
        + '</select>';
    return '<div class="pic">' + head + '<div class="row">' + pick
      + '<button data-del="' + i + '" aria-label="' + WallCore.esc(WallCore.t('del')) + '">✕</button></div></div>';
  }).join('') : '<div style="font-size:11px;opacity:.55;font-weight:800;padding:8px">' + WallCore.esc(WallCore.t('picNone')) + '</div>';
}

function addPicFiles(files){
  var big = 0, bad = 0;
  Array.prototype.slice.call(files || []).forEach(function(f){
    var img = (f.type && f.type.indexOf('image/') === 0), mdl = WallCore.isModelFile(f);
    if(!img && !mdl){ bad++; return; }
    if(f.size > 12 * 1024 * 1024){ big++; return; }
    var r = new FileReader();
    r.onload = function(){
      var rec = { name: f.name, data: String(r.result), use: 'card' };
      if(mdl){ rec.kind = 'model'; rec.ext = WallCore.modelExt(f.name); }
      pics.push(rec); paintPics();
    };
    r.readAsDataURL(f);
  });
  if(big) toast(WallCore.t('picTooBig', { n: big }));
  else if(bad) toast(WallCore.t('picBadType', { n: bad }));
}

function applySprites(arr){
  window.WALL_SPRITES = WallCore.validSprites(arr) || WallCore.SPRITE_DEF.slice();
  try{ localStorage.setItem((window.WALL_ID || 'ezsheet') + '_sprites', JSON.stringify(window.WALL_SPRITES)); }catch(e){}
  if(WallCore.fxPaint) WallCore.fxPaint();
  if(WallCore.fxState && WallCore.fxState().fly){
    WallCore.fxSetFly(false);
    setTimeout(function(){ WallCore.fxSetFly(true); }, 80);
  }
}

var sprUI = $('sprStrip') ? WallCore.spriteStrip($('sprStrip'), function(){ return window.WALL_SPRITES || ['bee:0','bee:1']; }, applySprites) : null;

if($('drop')) $('drop').addEventListener('click', function(){ if($('picFile')) $('picFile').click(); });
if($('picFile')) $('picFile').addEventListener('change', function(e){ addPicFiles(e.target.files); e.target.value = ''; });
if($('pics')){
  $('pics').addEventListener('change', function(e){
    var sel = e.target.closest('select');if(sel && pics[+sel.dataset.i]) pics[+sel.dataset.i].use = sel.value;
  });
  $('pics').addEventListener('click', function(e){
    var b = e.target.closest('[data-del]');if(b){ pics.splice(+b.dataset.del, 1); paintPics(); }
  });
}
if($('btnPicAdd')) $('btnPicAdd').addEventListener('click', function(){
  var zone = $('picZone') ? $('picZone').value : 'b';
  var now = Date.now(), made = [];
  pics.filter(function(p){ return p.use === 'card'; }).forEach(function(p){
    var o;
    if(p.kind === 'model'){
      o = WallCore.normalize({ type: 'model', mdl: { d: p.data, e: p.ext, n: p.name }, zone: zone, w: WallCore.MODEL_W || 380, h: WallCore.MODEL_H || 285 });
    } else {
      var html = '<div style="margin:0;background:#fff"><img src="' + p.data + '" alt="' + WallCore.esc(p.name) + '" style="display:block;width:100%;height:auto"></div>';
      o = WallCore.normalize({ _raw: html, type: 'html', html: html, zone: zone, w: 340, h: 260, pic: true });
    }
    if(o){ o.at = now; o.title = o.title || p.name; made.push(o); }
  });
  if(!made.length) return;
  pics = pics.filter(function(p){ return p.use !== 'card'; });
  paintPics();
  addToSheet(made.map(function(o){ return o._raw; }), zone);
  toast(WallCore.t('picAdded', { n: made.length }) + ' · Push to save');
});
if($('btnSprites')) $('btnSprites').addEventListener('click', function(){
  var arr = pics.filter(function(p){ return p.use === 'sprite' && p.kind !== 'model'; }).map(function(p){ return p.data; }).slice(0, 4);
  if(!arr.length) return;
  applySprites(arr);
  pics = pics.filter(function(p){ return p.use !== 'sprite'; });
  paintPics();
  if(sprUI && sprUI.paint) sprUI.paint();
  toast(WallCore.t('spriteOk', { n: arr.length }));
});
if($('btnSpriteReset')) $('btnSpriteReset').addEventListener('click', function(){
  applySprites(null);
  if(sprUI && sprUI.paint) sprUI.paint();
  toast(WallCore.t('spriteReset'));
});

/* ==================== tabMedia: Multiple File Types Upload & Preview ==================== */
var mediaQueue = [];

function formatMediaSize(bytes){
  if(!bytes || bytes <= 0) return '0 B';
  var units = ['B', 'KB', 'MB', 'GB'];
  var i = Math.floor(Math.log(bytes) / Math.log(1024));
  return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
}

function detectFileKind(name, type){
  var ext = (name.split('.').pop() || '').toLowerCase();
  type = (type || '').toLowerCase();
  if(type.startsWith('image/') || ['png','jpg','jpeg','gif','webp','bmp','ico','avif','svg'].indexOf(ext) >= 0 || type === 'image/svg+xml') return 'image';
  if(type === 'application/pdf' || ext === 'pdf') return 'pdf';
  if(type.startsWith('video/') || ['mp4','webm','ogg','mov','m4v','mkv'].indexOf(ext) >= 0) return 'video';
  if(type.startsWith('audio/') || ['mp3','wav','ogg','m4a','aac','flac'].indexOf(ext) >= 0) return 'audio';
  if(type === 'text/html' || ext === 'html' || ext === 'htm') return 'html';
  return 'file';
}

function paintMedia(){
  var box = $('mediaUploadList');
  if(!box) return;

  if(!mediaQueue.length){
    box.innerHTML = '';
    return;
  }

  box.innerHTML = mediaQueue.map(function(item){
    var badgeCls = 'img', badgeLabel = 'IMG';
    if(item.kind === 'pdf'){ badgeCls = 'pdf'; badgeLabel = 'PDF'; }
    else if(item.kind === 'video'){ badgeCls = 'vid'; badgeLabel = 'VIDEO'; }
    else if(item.kind === 'audio'){ badgeCls = 'aud'; badgeLabel = 'AUDIO'; }
    else if(item.kind === 'html'){ badgeCls = 'htm'; badgeLabel = 'HTML'; }
    else if(item.kind === 'file'){ badgeCls = 'mdl'; badgeLabel = 'FILE'; }

    var isErr = item.status === 'error';
    var isDone = item.status === 'finished';

    var statusColor = isDone ? '#16a34a' : (isErr ? '#dc2626' : '#0284c7');
    var statusText = isDone ? '✓ Added to Content in sheet' : (isErr ? ('✕ ' + (item.error || 'Upload failed')) : ('Uploading ' + item.progress + '%'));
    var progColor = isDone ? '#16a34a' : (isErr ? '#ef4444' : '#0284c7');

    return '<div class="media-item" style="display:flex;flex-direction:column;gap:6px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px">'
      +   '<div style="display:flex;align-items:center;gap:8px;min-width:0;flex:1">'
      +     '<span class="media-badge ' + badgeCls + '">' + badgeLabel + '</span>'
      +     '<span style="font-size:12px;font-weight:700;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + WallCore.esc(item.name) + '">' + WallCore.esc(item.name) + '</span>'
      +     '<span style="font-size:11px;color:#64748b;font-weight:600;flex-shrink:0">(' + item.sizeFormatted + ')</span>'
      +   '</div>'
      +   '<div style="display:flex;align-items:center;gap:6px;flex-shrink:0">'
      +     (isErr ? '<button type="button" class="tool-btn" data-media-retry="' + item.id + '" style="padding:2px 8px;font-size:11px;color:#2563eb;background:#eff6ff;border-color:#bfdbfe">Retry</button>' : '')
      +     '<button type="button" class="tool-btn" data-media-remove="' + item.id + '" style="padding:2px 6px;font-size:11px;color:#dc2626;background:#fef2f2;border-color:#fecaca" title="Remove">Remove</button>'
      +   '</div>'
      + '</div>'
      + '<div style="height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden;position:relative">'
      +   '<div style="height:100%;width:' + item.progress + '%;background:' + progColor + ';transition:width 0.2s"></div>'
      + '</div>'
      + '<div style="display:flex;justify-content:space-between;align-items:center;font-size:11px">'
      +   '<span style="color:' + statusColor + ';font-weight:600">' + WallCore.esc(statusText) + '</span>'
      +   '<span style="color:#64748b;font-weight:600">' + item.progress + '%</span>'
      + '</div>'
      + '</div>';
  }).join('');
}

/* hotfix: uploads need a signed-in session (server answers 401 otherwise) */
window.EZ_AUTHED = null;
function ezShowSignIn(){
  var si = $('mediaSignIn'), dr = $('mediaDrop');
  if(si) si.hidden = false;
  if(dr) dr.hidden = true;
}
/* item 20: the wall's sign-in prompt opens the sign-in popover next to the cursor (no page change) */
(function(){
  var a = $('mediaSignIn') && $('mediaSignIn').querySelector('a');
  if(!a) return;
  a.addEventListener('click', function(e){
    if(!window.WallCore || typeof WallCore.signIn !== 'function' || e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    WallCore.signIn({ x: e.clientX, y: e.clientY }, 'Sign in to upload').then(function(ok){
      if(!ok) return;
      window.EZ_AUTHED = true;
      var si = $('mediaSignIn'), dr = $('mediaDrop');
      if(si) si.hidden = true;
      if(dr) dr.hidden = false;
    });
  });
})();
try{
  fetch('/api/wall/auth/me', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
    .then(function(r){ return r.ok ? r.json() : null; })
    .then(function(d){
      window.EZ_AUTHED = !!(d && d.authenticated && d.user && d.user.id);
      if(!window.EZ_AUTHED && (window.WALL_ID || 'ezsheet') === 'ezsheet') ezShowSignIn();
    }).catch(function(){});
}catch(e){}

function startItemUpload(item){
  if(item.size > 100 * 1024 * 1024){
    item.status = 'error';
    item.error = 'File exceeds 100MB limit';
    item.progress = 0;
    paintMedia();
    return;
  }

  // HTML: inlined as a sandboxed card, not stored on the server
  if(item.kind === 'html'){
    item.status = 'uploading';
    item.progress = 50;
    paintMedia();
    var reader = new FileReader();
    reader.onload = function(e){
      var rawHtml = String(e.target.result || '');
      var escaped = rawHtml.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
      var block = '<div class="sandboxed-html-card" style="border:1px solid #cbd5e1;border-radius:10px;overflow:hidden;background:#fff;width:100%;display:flex;flex-direction:column;font-family:sans-serif">'
        + '  <div style="background:#1e293b;color:#f8fafc;padding:7px 12px;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:space-between">'
        + '    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:80%">🌐 ' + WallCore.esc(item.name) + '</span>'
        + '    <span style="font-size:10px;background:#334155;color:#cbd5e1;padding:2px 6px;border-radius:4px">Sandboxed HTML</span>'
        + '  </div>'
        + '  <iframe srcdoc="' + escaped + '" sandbox="allow-scripts" style="width:100%;min-height:280px;height:320px;border:none;background:#fff" title="' + WallCore.esc(item.name) + '"></iframe>'
        + '</div>';
      item.progress = 100;
      item.status = 'finished';
      item.cardBlock = block;
      paintMedia();
      addToSheet([block], 'b', true);
      toast('✓ Added ' + item.name + ' to Content (inlined). Push to create card.');
    };
    reader.onerror = function(){
      item.status = 'error';
      item.error = 'Failed to read HTML file';
      paintMedia();
    };
    reader.readAsText(item.file);
    return;
  }

  var finishWithUrl = function(url, isFallback){
    // item 15: absolute URL so the block works in any frame, export or host
    if(url.charAt(0) === '/' && url.charAt(1) !== '/') url = location.origin + url;
    item.progress = 100;
    item.status = 'finished';
    var block = '';
    if(item.kind === 'image'){
      block = '<img src="' + url + '" alt="' + WallCore.esc(item.name) + '" style="max-width:100%;height:auto;display:block;border-radius:8px;margin:0 auto" />';
    } else if(item.kind === 'video'){
      block = '<video src="' + url + '" controls playsinline preload="metadata" style="max-width:100%;width:100%;display:block;border-radius:8px;background:#000"></video>'
        + '<div style="text-align:right;font:11px sans-serif;margin-top:4px"><a href="' + url + '" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:none">Open video ↗</a></div>';
    } else if(item.kind === 'audio'){
      block = '<div style="background:linear-gradient(135deg,#0f172a,#1e1b4b);padding:14px;border-radius:10px;color:#fff;display:flex;flex-direction:column;gap:8px">'
        + '<div style="font-size:12px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">🎵 ' + WallCore.esc(item.name) + '</div>'
        + '<audio src="' + url + '" controls style="width:100%" preload="metadata"></audio>'
        + '</div>';
    } else if(item.kind === 'pdf'){
      block = '<div class="pdf-link-card" style="border:1px solid #cbd5e1;border-radius:10px;background:#ffffff;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);font-family:sans-serif">'
        + '  <div style="background:#1e293b;color:#f8fafc;padding:10px 14px;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:space-between">'
        + '    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:75%">📄 ' + WallCore.esc(item.name) + '</span>'
        + '    <a href="' + url + '" target="_blank" rel="noopener noreferrer" style="color:#38bdf8;background:#334155;border-radius:4px;padding:3px 10px;text-decoration:none;font-size:11px;font-weight:700">Open ↗</a>'
        + '  </div>'
        + '  <iframe src="' + url + '#toolbar=0" style="width:100%;height:320px;border:none;background:#fff" title="' + WallCore.esc(item.name) + '"></iframe>'
        + '</div>';
    } else {
      block = '<div style="border:1px solid #cbd5e1;border-radius:10px;background:#ffffff;padding:12px 16px;display:flex;align-items:center;justify-content:space-between">'
        + '<span>📁 ' + WallCore.esc(item.name) + '</span>'
        + '<a href="' + url + '" download="' + WallCore.esc(item.name) + '" style="font-size:11px;color:#2563eb;text-decoration:none;font-weight:700">Download ⬇</a>'
        + '</div>';
    }
    item.cardBlock = block;
    paintMedia();
    addToSheet([block], 'b', true);
    toast('✓ ' + item.name + (isFallback ? ' added (local preview). Push to create card.' : ' uploaded to Content. Push to create card.'));
  };

  var failUpload = function(msg){
    item.status = 'error';
    item.error = msg || 'Upload failed';
    paintMedia();
    toast('✗ ' + item.name + ': ' + item.error);
  };
  /* hotfix: no silent inline fallback except small images */
  var fallbackToLocal = function(msg){
    if(!(item.kind === 'image' && item.size <= 2 * 1024 * 1024)){
      failUpload(msg || 'Upload failed');
      return;
    }
    try {
      var r = new FileReader();
      r.onload = function(ev){
        finishWithUrl(String(ev.target.result), true);
      };
      r.onerror = function(){
        try {
          finishWithUrl(URL.createObjectURL(item.file), true);
        } catch(e){
          item.status = 'error';
          item.error = 'Failed to load file';
          paintMedia();
        }
      };
      r.readAsDataURL(item.file);
    } catch(err){
      try {
        finishWithUrl(URL.createObjectURL(item.file), true);
      } catch(e){
        item.status = 'error';
        item.error = 'Upload failed';
        paintMedia();
      }
    }
  };

  // Server upload for Image, Video, Audio, PDF, File
  var xhr = new XMLHttpRequest();
  item.xhr = xhr;
  item.status = 'uploading';
  item.progress = 0;
  item.error = null;
  paintMedia();

  var fd = new FormData();
  fd.append('file', item.file);
  var wallId = window.WALL_ID || 'ezsheet';
  fd.append('wall_id', wallId);

  if(wallId === 'ezsheet' && window.EZ_AUTHED === false){
    ezShowSignIn();
    failUpload('Sign in to upload');
    return;
  }
  var targetUrl = (wallId === 'ezsheet')
    ? '/api/wall/upload-ezsheet-file'   // homepage: signed-in users only (401 otherwise)
    : '/api/wall/' + encodeURIComponent(wallId) + '/media';

  xhr.open('POST', targetUrl, true);
  xhr.withCredentials = true;
  xhr.timeout = 0;
  var tok = (typeof WallCore !== 'undefined' && WallCore.ownerToken) ? WallCore.ownerToken() : '';
  if(tok) xhr.setRequestHeader('x-wall-owner', tok);
  try {
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta && csrfMeta.content) xhr.setRequestHeader('X-CSRF-TOKEN', csrfMeta.content);
    var xs = WallCore.csrfHeaders ? WallCore.csrfHeaders() : {};
    if (xs['X-XSRF-TOKEN']) xhr.setRequestHeader('X-XSRF-TOKEN', xs['X-XSRF-TOKEN']);
  } catch(e){}
  xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

  xhr.upload.onprogress = function(ev){
    if(ev.lengthComputable){
      item.progress = Math.min(99, Math.round((ev.loaded / ev.total) * 100));
      paintMedia();
    }
  };

  xhr.onload = function(){
    item.xhr = null;
    if(xhr.status >= 200 && xhr.status < 300){
      try{
        var res = JSON.parse(xhr.responseText);
        if(res.success && res.url){
          finishWithUrl(res.url, false);
          return;
        }
      }catch(ex){}
    }
    if(xhr.status === 401){
      window.EZ_AUTHED = false; ezShowSignIn();
      failUpload('Sign in to upload');
      return;
    }
    var em = 'HTTP ' + xhr.status;
    try { var ej = JSON.parse(xhr.responseText); if(ej && (ej.message || ej.error)) em = ej.message || ej.error; } catch(ex){}
    if(xhr.status === 413) em = 'File too large for the server (HTTP 413)';
    fallbackToLocal(em);
  };

  xhr.onerror = function(){
    item.xhr = null;
    fallbackToLocal();
  };

  xhr.ontimeout = function(){
    item.xhr = null;
    fallbackToLocal();
  };

  xhr.onabort = function(){
    item.xhr = null;
  };

  xhr.send(fd);
}

function handleMediaFiles(fileList){
  var files = Array.from(fileList || []);
  if(!files.length) return;
  files.forEach(function(file){
    var kind = detectFileKind(file.name, file.type);
    var item = {
      id: 'mf_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7),
      file: file,
      name: file.name,
      ext: (file.name.split('.').pop() || '').toLowerCase(),
      kind: kind,
      size: file.size,
      sizeFormatted: formatMediaSize(file.size),
      status: 'uploading',
      progress: 0,
      error: null,
      xhr: null,
      cardBlock: null
    };
    mediaQueue.push(item);
    startItemUpload(item);
  });
  paintMedia();
}

if($('mediaDrop')){
  $('mediaDrop').addEventListener('click', function(){
    if($('mediaFileInput')) $('mediaFileInput').click();
  });
  $('mediaDrop').addEventListener('dragover', function(e){
    e.preventDefault(); e.stopPropagation();
    $('mediaDrop').classList.add('over');
  });
  $('mediaDrop').addEventListener('dragleave', function(e){
    e.preventDefault(); e.stopPropagation();
    $('mediaDrop').classList.remove('over');
  });
  $('mediaDrop').addEventListener('drop', function(e){
    e.preventDefault(); e.stopPropagation();
    $('mediaDrop').classList.remove('over');
    if(e.dataTransfer && e.dataTransfer.files) handleMediaFiles(e.dataTransfer.files);
  });
}

if($('mediaFileInput')){
  $('mediaFileInput').addEventListener('change', function(e){
    handleMediaFiles(e.target.files);
    e.target.value = '';
  });
}

if($('mediaSupportedLink')){
  $('mediaSupportedLink').addEventListener('click', function(e){
    e.preventDefault();
    var box = $('mediaSupportedBox');
    if(box) box.style.display = box.style.display === 'none' ? 'block' : 'none';
  });
}

if($('btnCloseSupported')){
  $('btnCloseSupported').addEventListener('click', function(){
    var box = $('mediaSupportedBox');
    if(box) box.style.display = 'none';
  });
}

if($('mediaUploadList')){
  $('mediaUploadList').addEventListener('click', function(e){
    var retryBtn = e.target.closest('[data-media-retry]');
    if(retryBtn){
      var retryId = retryBtn.getAttribute('data-media-retry');
      var itemToRetry = mediaQueue.find(function(it){ return it.id === retryId; });
      if(itemToRetry) startItemUpload(itemToRetry);
      return;
    }
    var removeBtn = e.target.closest('[data-media-remove]');
    if(removeBtn){
      var removeId = removeBtn.getAttribute('data-media-remove');
      var idx = mediaQueue.findIndex(function(it){ return it.id === removeId; });
      if(idx >= 0){
        var itemToRemove = mediaQueue[idx];
        if(itemToRemove.xhr){
          try{ itemToRemove.xhr.abort(); }catch(err){}
        }
        mediaQueue.splice(idx, 1);
        paintMedia();
      }
    }
  });
}

/* Zone Order Chips Dragging */
(function dragChips(){
  var box = $('zdrag');if(!box) return;
  var src = null;
  box.addEventListener('dragstart', function(e){
    var c = e.target.closest('.zchip:not(.fixed)');if(!c){ e.preventDefault(); return; }
    src = c; c.classList.add('drag');
    try{ e.dataTransfer.setData('text/plain', c.dataset.z); }catch(err){}
  });
  box.addEventListener('dragover', function(e){
    var c = e.target.closest('.zchip:not(.fixed)');if(!c || c === src) return;
    e.preventDefault(); c.classList.add('over');
  });
  box.addEventListener('dragleave', function(e){
    var c = e.target.closest('.zchip');if(c) c.classList.remove('over');
  });
  box.addEventListener('drop', function(e){
    var c = e.target.closest('.zchip:not(.fixed)');if(!c || !src || c === src) return;
    e.preventDefault(); c.classList.remove('over');
    setOrder([c.dataset.z === currentZOrder[0] ? src.dataset.z : c.dataset.z, c.dataset.z === currentZOrder[0] ? c.dataset.z : src.dataset.z, 'b']);
  });
  box.addEventListener('dragend', function(){
    box.querySelectorAll('.zchip').forEach(function(c){ c.classList.remove('drag', 'over'); });
    src = null;
  });
  box.addEventListener('click', function(e){
    var c = e.target.closest('.zchip:not(.fixed)');if(!c) return;
    var top = currentZOrder.filter(function(z){ return z !== 'b'; });
    setOrder([top[1], top[0], 'b']);
  });
})();

// Hook tab clicks & textarea events
if($('copyBtn')) $('copyBtn').addEventListener('click', copyFullHTML);
if($('dlBtn')) $('dlBtn').addEventListener('click', downloadStandalone);
if($('btnDownloadJSON')) $('btnDownloadJSON').addEventListener('click', downloadSheet);
if($('btnClearWall')) $('btnClearWall').addEventListener('click', clearWall);
var displayToken = window.WALL_FUNNEL || ((window.WALL_SLUG && window.WALL_SLUG !== 'ezsheet') ? window.WALL_SLUG : '');
if(!displayToken){
  try {
    var pPath = (window.parent && window.parent.location) ? window.parent.location.pathname : (window.location ? window.location.pathname : '');
    var parts = (pPath || '').split('/').filter(Boolean);
    var cand = (parts[0] === 'X' && parts[1]) ? parts[1] : parts[0];
    var blocked = ['dashboard', 'marketplace', 'login', 'register', 'ezbar', 'ezsheet'];
    if(cand && blocked.indexOf(cand.toLowerCase()) === -1) {
      displayToken = cand;
    }
  } catch(e){}
}
if(!displayToken) displayToken = window.WALL_ID || 'Wall';
if($('idDisplay')) $('idDisplay').textContent = displayToken;
if($('idDisplay2')) $('idDisplay2').textContent = displayToken;
paintChips();

EZED = EzSheetEditor.mount(document.getElementById('panel'), {
  onPush: function(){ batchImport(); },
  onCancel: function(){ closePanel(); },
  onTab: showTab,
  onInput: function(){ var t = $('input'); if(t) t.dataset.dirty = '1'; }
});
document.addEventListener('keydown', function(e){
  if(e.key === 'Escape'){ var p = $('panel'); if(p && !p.classList.contains('hidden')) closePanel(); }
});

window.addEventListener('message', function(e){
  if(e.source !== window.parent) return;
  if(e.data && e.data.__ezsheetRequestItems){
    try {
      var el = document.getElementById('wall-data');
      if(el) parent.postMessage({__ezsheetItems: JSON.parse(el.textContent).list}, '*');
    } catch(err){}
  }
  if(e.data && e.data.__ezsheetPushResult){
    var cb = pushWait[e.data.requestId];
    if(cb){ delete pushWait[e.data.requestId]; cb(e.data.status, e.data.data); }
    return;
  }
  if(e.data && e.data.__ezsheetUpdatePayload && e.data.payload){
    try {
      var p = e.data.payload;
      if (typeof p.cols === 'number' && p.cols !== currentCols) {
        setCols(p.cols);
      }
      if (typeof p.car === 'boolean' && p.car !== currentCar) {
        setCar(p.car);
      }
      /* Parent payload goes through the same gate as every other snapshot. */
      if (Array.isArray(p.cards) && typeof p.rev === 'number') {
        WallCore.applySnapshot(p.cards, p.rev, { quiet: true });
        updateCount();
      }
    } catch(err){}
  }
});
/* item 14: ?ezsheet=edit (the 'Edit on ez.wiki' link from a subdomain) opens the editor for the owner */
if(window.isOwner){ var ezLoad = loadSheet(); if(window.EZ_OPEN_EDITOR) ezLoad.then(function(){ if(window.openPanel) window.openPanel(); }); }
</script>
${autoHeight ? '<script>(' + wallAutoHeightReporter.toString() + ')(window);</script>' : ''}
</body>
</html>`;

    try {
      iframe.srcdoc = doc;
      setError(null);
    } catch (e) {
      setError('Failed to render EzSheet preview');
      console.error('EzSheetPreview: error setting srcdoc', e);
    }
  }, [payload, title, syncUrl, frameCheckUrl, metaUrl, editable, sprites, isOwner, resolvedAuth, slug, messageId, autoHeight, frameless]);

  useEffect(() => {
    const handler = (e: MessageEvent) => {
      // Security check: Only accept messages originating strictly from this EzSheetPreview iframe
      if (!iframeRef.current || !iframeRef.current.contentWindow) return;
      if (e.source !== iframeRef.current.contentWindow) return;

      // Security check: Verify origin (must be same origin or 'null' from srcdoc, strictly rejecting any 3rd-party frames)
      if (e.origin !== 'null' && e.origin !== window.location.origin) return;

      if (!e.data || typeof e.data !== 'object') return;
      if (typeof e.data.__ezWallDocH === 'number') {
        if (autoHeightRef.current) heightDamperRef.current?.push(e.data.__ezWallDocH);
        return;
      }
      if (e.data.__ezsheetSnapshot || e.data.__ezsheetWallSettingChanged) {
        internalUpdateRef.current = true;
      }
      // only snapshots carry a card list now (server order)
      const updatedCards = e.data.__ezsheetSnapshot ? e.data.cards : null;
      if (Array.isArray(updatedCards) && onItemsChangeRef.current) {
        onItemsChangeRef.current(updatedCards);
      }
      if (e.data.__ezsheetPushToWall && onPushToWallRef.current) {
        // Enforce authorization: only wall owner can push cards to the wall database
        const src = iframeRef.current.contentWindow;
        const requestId = e.data.requestId;
        const reply = (status: number, body: any) => {
          try { src.postMessage({ __ezsheetPushResult: true, requestId, status, data: body }, '*'); } catch (err) {}
        };
        if (!isOwner) {
          console.warn('[Security] Unauthorized push-to-wall rejected: caller is not the wall owner');
          reply(403, { error: 'forbidden' });
          return;
        }
        onPushToWallRef.current(e.data, reply);
      }
      if (e.data.__ezsheetDownload && e.data.text) {
        try {
          // Sanitize filename to prevent directory traversal or executable file downloads
          let safeName = String(e.data.name || 'wall-random.json')
            .replace(/[/\\]/g, '')
            .replace(/[^a-zA-Z0-9._-]/g, '_');
          if (!safeName.endsWith('.json') && !safeName.endsWith('.html') && !safeName.endsWith('.txt')) {
            safeName += '.json';
          }
          const blob = new Blob([e.data.text], { type: e.data.type || 'application/json' });
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          a.download = safeName;
          document.body.appendChild(a);
          a.click();
          setTimeout(() => {
            a.remove();
            URL.revokeObjectURL(url);
          }, 1500);
        } catch (err) {}
      }
    };
    window.addEventListener('message', handler);
    return () => window.removeEventListener('message', handler);
  }, [onItemsChange, onPushToWall, isOwner]);

  if (!content && !wallId) {
    return (
      <div className={`w-full flex items-center justify-center bg-gray-50 border border-gray-200 rounded-lg p-8 ${className}`}>
        <div className="text-center text-gray-500 text-sm">
          <div className="text-2xl mb-2">🧱</div>
          <p>No wall card content</p>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className={`w-full bg-red-50 border border-red-200 rounded-lg p-4 ${className}`}>
        <p className="text-sm text-red-600">{error}</p>
      </div>
    );
  }

  return (
    <div
      className={frameless
        ? `w-full overflow-hidden relative ${className}`
        : `w-full bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm relative ${className}`}
      style={frameless ? { background: 'transparent', border: 0, borderRadius: 0, boxShadow: 'none' } : undefined}
    >
      <iframe
        ref={iframeRef}
        title={title}
        className={frameless ? 'w-full block' : 'w-full block bg-[#F7F5F1]'}
        style={{
          height: `${autoHeight ? Math.max(minHeight, autoHRef.current ?? height) : height}px`,
          border: 0,
          ...(frameless ? { background: 'transparent', colorScheme: 'normal' } : {}),
        }}
        sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-forms allow-presentation allow-same-origin allow-downloads"
        loading="lazy"
      />
    </div>
  );
};

/* ============================================================
 * Client-side block parser (mirrors core's parseOne)
 * ============================================================ */
function parseOneBlock(raw: string): any | null {
  raw = String(raw || '').trim();
  if (!raw) return null;

  if (/^<md>[\s\S]*<\/md>$/i.test(raw)) {
    return { _raw: raw, type: 'md', md: raw.replace(/^<md>[ \t]*\r?\n?/i, '').replace(/\r?\n?[ \t]*<\/md>$/i, ''), w: 420, h: 520 };
  }

  // HTML content detection
  const isHtml =
    /^<!doctype\s+html/i.test(raw) ||
    /^<html\b/i.test(raw) ||
    /^<\/?(?:html|head|body|div|p|span|a|h[1-6]|ul|ol|li|table|tr|td|th|tbody|thead|tfoot|iframe|embed|object|video|audio|canvas|svg|button|form|input|select|textarea|label|style|script|link|meta|section|article|header|footer|nav|aside|main|figure|figcaption|code|pre|blockquote|b|strong|i|em|mark|del|ins|hr|br|img|picture|source|details|summary|dialog|template)\b/i.test(raw) ||
    /<\/?(?:div|iframe|p|span|h[1-6]|table|tr|td|th|script|style|svg|canvas|button|form|video|audio|ul|ol|li|section|article|header|footer|blockquote)\b[^>]*>/i.test(raw) ||
    /<([a-z][a-z0-9]*)\b[^>]*>[\s\S]*<\/\1>/i.test(raw);

  if (isHtml || raw.startsWith('<')) {
    const modelMatch = raw.match(/^<model\b/i);
    if (modelMatch) {
      const g = (k: string) => ((raw.match(new RegExp('\\b' + k + '=["\']([^"\']*)["\']', 'i')) || [])[1] || '');
      const src = g('src').replace(/&amp;/g, '&');
      const type = g('type').toLowerCase() || (src.match(/^data:model\/(gltf-binary|gltf\+json|stl|obj)/i) || [])[1] || '';
      const ex = type === 'gltf-binary' ? 'glb' : type === 'gltf+json' ? 'gltf' : type;
      if (ex && /^(glb|gltf|stl|obj)$/.test(ex) && src) {
        return {
          _raw: raw, type: 'model',
          mdl: /^https?:\/\//i.test(src) ? { u: src, e: ex, n: g('name') } : { d: src, e: ex, n: g('name') },
          w: 380, h: 285,
        };
      }
    }
    const srcMatch = raw.match(/\bsrc=["']([^"']+)["']/i);
    const src = (srcMatch?.[1] || '').replace(/&amp;/g, '&');
    const ytMatch = (src || raw).match(
      /(?:youtube\.com\/(?:embed\/|watch\?v=|shorts\/|live\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/
    );
    if (ytMatch) {
      return {
        type: 'youtube', id: ytMatch[1],
        url: src || `https://www.youtube.com/watch?v=${ytMatch[1]}`,
        w: 340, h: 220, _raw: raw,
      };
    }
    if (/^<iframe\b[^>]*>\s*<\/iframe>$/i.test(raw) && src) {
      return { type: 'web', url: src, w: 340, h: 220, _raw: raw };
    }
    return { type: 'html', html: raw, w: 520, h: 720, _raw: raw };
  }

  const yt = raw.match(
    /(?:youtube\.com\/(?:embed\/|watch\?v=|shorts\/|live\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/
  );
  if (yt) {
    return { type: 'youtube', id: yt[1], url: raw, w: 340, h: 220, _raw: raw };
  }

  if (/^data:image\/[a-z0-9+.-]+;base64,/i.test(raw)) {
    const html = `<div style="margin:0;background:#fff;display:flex;align-items:center;justify-content:center;min-height:160px;overflow:hidden;border-radius:8px"><img src="${raw}" alt="Image" style="display:block;width:100%;height:auto;max-height:100%;object-fit:contain" /></div>`;
    return { type: 'html', html, w: 340, h: 260, _raw: raw, pic: true };
  }

  if (/^\/upload\S+\.(png|jpe?g|gif|webp|svg|bmp|avif)$/i.test(raw)) {
    const html = `<div style="margin:0;background:#fff;display:flex;align-items:center;justify-content:center;min-height:160px;overflow:hidden;border-radius:8px"><img src="${raw}" alt="Image" style="display:block;width:100%;height:auto;max-height:100%;object-fit:contain" /></div>`;
    return { type: 'html', html, w: 340, h: 260, _raw: raw, pic: true };
  }

  let s = raw;
  let isPotentialUrl = false;
  if (/^https?:\/\//i.test(s)) {
    isPotentialUrl = !/\s/.test(s);
  } else if (!/\s/.test(s) && /^[^\/\s]+\.[a-z]{2,}(\/|:|\?|$)/i.test(s)) {
    s = 'https://' + s;
    isPotentialUrl = true;
  }
  if (isPotentialUrl) {
    try {
      const u = new URL(s);
      if (u.protocol === 'http:' || u.protocol === 'https:') {
        if (u.protocol === 'https:' && /\.(glb|gltf|stl|obj)$/i.test(u.pathname)) {
          return {
            type: 'model',
            mdl: { u: u.href, e: u.pathname.toLowerCase().replace(/^.*\./, ''), n: decodeURIComponent(u.pathname.split('/').pop() || '') },
            w: 380, h: 285, _raw: raw,
          };
        }
        if (/\.(png|jpe?g|gif|webp|svg|bmp|avif)$/i.test(u.pathname)) {
          const html = `<div style="margin:0;background:#fff;display:flex;align-items:center;justify-content:center;min-height:160px;overflow:hidden;border-radius:8px"><img src="${u.href}" alt="Image" style="display:block;width:100%;height:auto;max-height:100%;object-fit:contain" /></div>`;
          return { type: 'html', html, w: 340, h: 260, _raw: raw, pic: true };
        }
        return { type: 'web', url: u.href, w: 340, h: 220, _raw: raw };
      }
    } catch {}
  }

  // Automatic Markdown detection
  const isMd =
    !isHtml &&
    (/(^|\n) {0,3}#{1,6}[ \t]+\S+/m.test(raw) ||
    /(^|\n) {0,3}(`{3,}|~{3,})/.test(raw) ||
    /`[^`\n]+`/.test(raw) ||
    /(^|\n) {0,3}>\s*\S+/m.test(raw) ||
    /(^|\n) {0,3}([-*+]|\d{1,9}[.)])[ \t]+\S+/m.test(raw) ||
    /\*\*[^*\n]+\*\*/.test(raw) || /__[^_\n]+__/.test(raw) ||
    /\*[^*\n]+\*/.test(raw) || /(^|[^\w])_[^_\n]+_(?!\w)/.test(raw) ||
    /~~[^~\n]+~~/.test(raw) || /==[^=\n]+==/.test(raw) ||
    /!?\[[^\]\n]*\]\([^)\s]+\)/.test(raw) ||
    /(^|\n) {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*($|\n)/m.test(raw) ||
    /\|.+?\|\s*\n\s*\|? *[-:]+[-| :]*\|?/.test(raw) ||
    /\[[ xX]\]\s+/.test(raw) ||
    (raw.includes('\n') && !raw.startsWith('<')) ||
    (/\s{1,}/.test(raw) && !raw.startsWith('<')));

  if (isMd && raw.length <= 60000) {
    return { _raw: `<md>\n${raw}\n</md>`, type: 'md', md: raw, w: 420, h: 520 };
  }

  return null;
}

export default EzSheetPreview;
export type { EzSheetItem, EzSheetPreviewProps };