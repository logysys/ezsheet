<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>V620.01 Wall</title>
<script src="https://unpkg.com/zdog@1/dist/zdog.dist.min.js"></script>
<script>
  window.auth = {
    user: <?php echo json_encode(auth()->check() ? ['id' => auth()->id(), 'name' => auth()->user()->name, 'email' => auth()->user()->email] : null); ?>
  };
</script>
<!-- ===== 共用樣式：編輯器與「獨立牆」共用同一份，匯出時直接複製這個區塊 ===== -->
<style id="wall-css">
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
/* Zone C: an infomercial marquee above Zone A, built from the same band code. */
.zone-c{margin:0 auto 14px}
#wall[hidden]{display:none}
/* Zone order button: shows the current order, e.g. C·A·B */
.pill.zord .lbl{letter-spacing:.08em}
.pill.zone{min-width:34px;justify-content:center;gap:4px}
@media (max-width:620px){.pill.zone .lbl{font-size:10px}}
.zn{font-size:9px;opacity:.75;font-weight:900}
.pill.zone[aria-pressed="false"]{opacity:.5}
.pill.zone[aria-pressed="true"]{background:var(--ink);color:#fff}
/* 瀑布流：位置由程式計算（left/top），卡片節點永遠不搬動 */
.masonry>.item{position:absolute;left:0;top:0}
.masonry.ready>.item{transition:left .4s cubic-bezier(.2,.8,.2,1),top .4s cubic-bezier(.2,.8,.2,1),width .4s cubic-bezier(.2,.8,.2,1),height .4s cubic-bezier(.2,.8,.2,1)}
body.resizing .masonry>.item,body.shuffling .masonry>.item{transition:none}
.masonry>.item.dragging{z-index:5}
@media (prefers-reduced-motion:reduce){.masonry.ready>.item{transition:none}}
.item{max-width:100%;border-radius:18px;overflow:hidden;background:#111;border:2.5px solid var(--ink);position:relative;box-shadow:0 10px 24px rgba(0,0,0,.14)}
.item iframe{width:100%;height:100%;border:0;display:block;background:#fff}
.item iframe.embed{background:transparent}
/* + / − : position comes from .card-tools (lower right, shared EZ_CARD_CSS in wall-core) */
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
/* 訪客自己插的卡：邊框換成 teal，跟原本的內容分得出來（inset 陰影會被 iframe 蓋掉） */
.item.guest{border-color:var(--teal);box-shadow:0 10px 24px rgba(14,124,123,.28)}
/* [hidden] 遇到 .pill 的 display:inline-flex 會失效，要明確蓋回去 */
.pill[hidden],.pill.sync[hidden],.pill.rev[hidden]{display:none}
/* 同步狀態的小圓點與版主的待審鍵 */
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
.rev-st-pend{background:#fef3c7;color:#b45309;border:1.5px dashed #f59e0b}
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
/* 插卡對話框 */
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
/* 卡片把手：右下角同時調寬高，側邊只調寬度。獨立牆的訪客也用同一套 */
.resize-anchor{position:absolute;right:0;bottom:0;width:44px;height:44px;cursor:nwse-resize;z-index:8;touch-action:none;background:linear-gradient(135deg,transparent 50%,rgba(255,226,122,0.95) 50%)}
.resize-anchor::after{content:'↘';position:absolute;right:5px;bottom:2px;font-weight:900;font-size:15px}
.side-anchor{position:absolute;right:0;top:0;bottom:44px;width:28px;max-width:30%;cursor:ew-resize;z-index:7;touch-action:none;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .15s}
.side-anchor::after{content:'';width:8px;height:54px;border-radius:999px;background:var(--ink);opacity:.35;box-shadow:0 0 0 2px rgba(255,255,255,.65)}
.item:hover .side-anchor{opacity:1}
body.resizing .side-anchor{opacity:1}
@media (pointer:coarse){.side-anchor{width:36px;opacity:.55}.side-anchor::after{width:10px;height:64px}}
body.resizing{user-select:none;cursor:nwse-resize}
body.resizing iframe{pointer-events:none}
/* 還沒載入（或已卸載）的卡：佔位，不跑任何嵌入內容 */
.ph{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;background:repeating-linear-gradient(135deg,#1b1b1b 0 14px,#222 14px 28px);color:#fff;font-weight:900;pointer-events:none}
.ph-i{font-size:22px;opacity:.75}
.ph-h{font-size:11px;opacity:.55;max-width:90%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* 無法嵌入的墓碑卡 */
.corner{position:absolute;left:0;top:0;width:52px;height:52px;z-index:5;pointer-events:none}
.corner svg{position:absolute;inset:0;width:100%;height:100%;overflow:visible}
.corner .c-fill{fill:var(--yellow)}
.corner .c-edge{fill:none;stroke:var(--ink);stroke-width:1.6}
.corner .c-lbl{position:absolute;left:-5px;top:12px;width:44px;text-align:center;font-size:9px;font-weight:900;line-height:10px;letter-spacing:-.15px;color:var(--green);transform:rotate(-45deg);white-space:nowrap}
.corner-dim{position:absolute;left:50%;bottom:7px;transform:translateX(-50%);z-index:6;pointer-events:none;background:rgba(17,17,17,.8);color:#fff;font-size:10px;font-weight:800;padding:2px 8px;border-radius:99px;opacity:0;transition:opacity .15s;white-space:nowrap}
.item:hover .corner-dim,.item.dragging .corner-dim{opacity:1}
/* A 區：頂部走馬燈（一次三組，每次滑一組，滑鼠移入就停） */
/* 操縱桿：A 區之下、B 區頂端；往下捲時吸附在畫面上方 */
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
/* 開場特效：B 區煙火、A 區飛船，各自跑完就自己移除 */
.fx-layer{position:fixed;left:0;top:0;z-index:8;pointer-events:none}
.fx-bee{position:fixed;left:0;top:0;z-index:9;pointer-events:none;width:max-content;will-change:transform;filter:drop-shadow(0 8px 14px rgba(0,0,0,.28))}
.fx-bee{transform-style:preserve-3d}
.fx-bee img,.fx-bee svg.zdog-svg{display:block;width:150px;height:120px;backface-visibility:visible;overflow:visible;pointer-events:auto;cursor:grab}
.fx-bee .wob{display:block;transform-style:preserve-3d;animation:fxwob .42s ease-in-out infinite alternate}
@keyframes fxwob{from{transform:translateY(-6px) rotateX(10deg) rotateZ(-4deg)}to{transform:translateY(6px) rotateX(-8deg) rotateZ(4deg)}}
@media (max-width:560px){.fx-bee img,.fx-bee svg.zdog-svg{width:110px;height:88px}}
.fx-spark{position:fixed;z-index:8;pointer-events:none;width:16px;height:16px;border-radius:50%;background:var(--yellow);box-shadow:0 0 22px 6px rgba(255,226,122,.9);animation:fxspark 1.2s ease-out forwards}
@keyframes fxspark{from{transform:scale(1);opacity:.95}to{transform:scale(.2) translateY(18px);opacity:0}}
@media (prefers-reduced-motion:reduce){.fx-layer,.fx-ship{display:none}}
/* 右上角版本標示 */
.ver-badge{position:fixed;top:10px;right:12px;z-index:75;padding:5px 10px;border:2px solid var(--ink);border-radius:999px;background:rgba(255,255,255,.88);color:var(--ink);font-size:11px;font-weight:900;letter-spacing:.04em;pointer-events:none;box-shadow:0 4px 12px rgba(0,0,0,.12)}
@media (max-width:420px){.ver-badge{top:auto;bottom:10px;right:8px;font-size:10px;padding:4px 8px;opacity:.85}}
.empty{padding:40px 16px;text-align:center;opacity:.55;font-weight:800}
/* 頂部列（編輯器與獨立牆共用） */
.fab-wrap{position:relative;z-index:1;max-width:100%;display:flex;gap:8px;align-items:center;background:#fff;border:2.5px solid var(--ink);border-radius:999px;padding:6px 12px;box-shadow:0 8px 24px rgba(0,0,0,.15)}
.fab{background:var(--teal);color:#fff;border:2px solid var(--ink);border-radius:999px;padding:9px 14px;font-weight:900;cursor:pointer;font-size:12px;max-width:34vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lang-select{border:2px solid var(--ink);border-radius:999px;padding:7px 10px;font-weight:800;background:#fff;font-size:12px}
button,select{font-family:inherit}
/* 窄螢幕：EzBar 折行，不要溢出畫面 */
@media (max-width:760px){
  .fab-wrap{flex-wrap:wrap;justify-content:center;border-radius:22px;padding:8px 10px}
  .fab{max-width:46vw}
}
/* 版面下拉 */
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
/* 沒有圖檔／3D 時整組特效鍵收起來 */
.fx-group{display:contents}
.fx-group[hidden]{display:none}
.ci{display:inline-flex;gap:2px;width:20px;height:12px}.ci i{flex:1;background:currentColor;border-radius:1.5px}
/* 匯出鍵：EzBar 最右邊的一組，牆上沒有卡片時整組收起來 */
.pill.exp{background:#F3E5F5;border-color:var(--ink)}
#copyBtn{margin-left:6px}
body.empty-wall .pill.exp{display:none}
/* EzBar: icon-only controls with tooltips; only the three zone buttons keep their text */
.pill .lbl{display:none}
.pill.zone .lbl{display:inline}
.pill{padding:7px 9px}
.pill.zone{padding:7px 11px}
@media (max-width:560px){.fab{max-width:28vw}}
@media (max-width:420px){.fab-wrap{gap:4px;padding:4px 6px}.pill{padding:6px 7px}.fab{padding:8px 10px;max-width:22vw}.lang-select{padding:6px 4px;max-width:64px}}
@media (prefers-reduced-motion:reduce){.pill .caret{transition:none}}
button:focus-visible,.tool-btn:focus-within,select:focus-visible{outline:3px solid var(--teal);outline-offset:2px}
.fab.badge{cursor:default}
/* 篩選列 */
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
/* 輪播：牆本身變成橫向捲動區，一格一張貼齊；卡片節點照舊不搬動 */
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
</style>
<!-- ===== 編輯器專用樣式（不會進獨立牆） ===== -->
<style>
.del,.edit,.tog{position:absolute;top:6px;width:18px;height:18px;padding:0;border-radius:50%;border:0;cursor:pointer;z-index:7;font-weight:900;font-size:9px;line-height:18px;text-align:center;opacity:.35;transition:opacity .15s}
.del{right:6px;background:rgba(0,0,0,.6);color:#fff}
.edit{right:28px;background:rgba(255,255,255,.75);color:#111}
.tog{right:50px;background:rgba(255,255,255,.75);color:#111;font-size:10px}
.item:hover .del,.item:hover .edit,.item:hover .tog,.del:focus-visible,.edit:focus-visible,.tog:focus-visible{opacity:1}
@media (pointer:coarse){.del,.edit,.tog{width:22px;height:22px;line-height:22px;font-size:10px;opacity:.5}.edit{right:32px}.tog{right:58px}}
@media (prefers-reduced-motion:reduce){.del,.edit,.tog{transition:none}}
/* 側邊把手：抓取區 28px（角落把手的兩倍寬），只調寬度 */
.import-panel{position:fixed;top:68px;left:50%;transform:translateX(-50%);width:min(920px,96vw);background:#fff;border:2.5px solid var(--ink);border-radius:20px;padding:16px;max-height:92vh;display:flex;flex-direction:column;z-index:85;overflow:auto}
.import-panel.hidden{display:none}
.import-panel textarea, .ta-wrap textarea, textarea#input{width:100%;flex:1;min-height:380px;border:2px solid var(--ink);border-radius:12px;padding:12px;font-family:monospace;background:var(--cloud);line-height:1.8;font-size:12px}
/* 編輯區側邊拉把：自己畫的捲軸，寬 30px（系統捲軸的兩倍），
   不受作業系統「覆蓋式捲軸」設定影響，一定看得見也抓得到 */
.ta-wrap{display:flex;gap:8px;flex:1;min-height:380px;position:relative}
/* 拉過之後高度就固定在使用者選的值，不再跟著面板伸縮 */
.ta-wrap.sized{flex:0 0 auto;height:var(--ta-h);min-height:0}
.ta-wrap.sized #input{min-height:0;height:100%}
#input{resize:none}
/* EzSheet 右下角的拉把：拉高度，旁邊的自訂捲軸跟著長 */
.ta-grip{position:absolute;right:6px;bottom:6px;width:22px;height:22px;z-index:4;
  cursor:ns-resize;border-radius:6px;touch-action:none;
  background:linear-gradient(135deg,transparent 0 46%,var(--ink) 46% 54%,transparent 54% 70%,var(--ink) 70% 78%,transparent 78%);
  opacity:.38;transition:opacity .15s}
.ta-grip:hover,.ta-grip:focus-visible{opacity:.95;outline:none}
.ta-grip:focus-visible{box-shadow:0 0 0 2px var(--teal)}

.ta-wrap #input{flex:1;min-height:0;scrollbar-width:none}
.ta-wrap #input::-webkit-scrollbar{width:0;height:0}
.ta-bar{width:30px;flex:0 0 30px;border:2px solid var(--ink);border-radius:999px;background:var(--cloud);position:relative;touch-action:none;cursor:pointer}
.ta-bar[hidden]{display:none}
.ta-thumb{position:absolute;left:3px;right:3px;top:0;min-height:56px;border-radius:999px;background:var(--teal);box-shadow:inset 0 0 0 1.5px var(--ink);cursor:grab}
.ta-bar.drag .ta-thumb{cursor:grabbing;background:#0b6b6a}
.import-panel{scrollbar-width:auto}
.toolbar{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0;align-items:center}
.tool-btn{border:2px solid var(--ink);border-radius:999px;padding:8px 14px;font-size:11px;font-weight:800;cursor:pointer;background:#fff}
.tool-btn.primary{background:var(--teal);color:#fff}
/* Zone order: drag the C and A chips; B is fixed at the bottom of the wall */
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
.pane-pics{display:flex;flex-direction:column;gap:10px;flex:1;min-height:320px}
.drop{border:2.5px dashed var(--ink);border-radius:16px;padding:22px;text-align:center;font-weight:800;font-size:12px;background:var(--cloud);cursor:pointer}
.drop.over{background:var(--yellow)}
.pics{display:flex;flex-wrap:wrap;gap:10px;overflow:auto;max-height:280px}
/* 3D 檔案沒有縮圖，用一塊標示格式與檔名的方塊代替 */
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
.toast{position:fixed;left:50%;bottom:24px;transform:translate(-50%,20px);background:var(--ink);color:#fff;padding:10px 16px;border-radius:999px;font-size:12px;font-weight:800;opacity:0;pointer-events:none;transition:opacity .2s,transform .2s;z-index:95;max-width:92vw}
.toast.show{opacity:1;transform:translate(-50%,0)}
@media (prefers-reduced-motion:reduce){.toast{transition:none}}
</style>
</head><body>
<div class="bar-zone" id="barZone" aria-label="EzBar">
<div class="fab-wrap">
<button class="globe" onclick="openPanel()" data-i18n-aria="openEditor">🌍</button>
<button id="fab" class="fab" onclick="openPanel()"><span id="idDisplay"></span></button>
<span id="menuSlot"></span>
<span id="shuffleSlot"></span>
<span id="carSlot"></span>
<span id="filtSlot"></span>
<span id="zoneSlot"></span>
<span id="fxSlot"></span>
<span id="expSlot"></span>
<span id="langSlot"></span>
</div>
</div>

<div id="panel" class="import-panel hidden">
<div style="display:flex;justify-content:space-between"><b>V620.01 <span data-i18n="title"></span> - <span id="idDisplay2"></span></b><button onclick="closePanel()" class="tool-btn" data-i18n-aria="close">✕</button></div>

<div class="toolbar">
<label class="tool-btn" style="background:#e3f2fd"><input type="file" id="fileUpload" accept="image/*,video/*,audio/*,.pdf,.html,.htm,.json,.txt,application/pdf,text/html" multiple hidden>📥 <span data-i18n="upload"></span></label>
<button class="tool-btn" style="background:#fff9c4" onclick="downloadJSON()">📤 <span data-i18n="download"></span></button>
<button class="tool-btn" style="background:#ff8a80" onclick="clearWall()">🗑️ <span data-i18n="clear"></span></button>
</div>

<div class="toolbar" style="gap:10px">
<span style="font-size:10px;opacity:.6" data-i18n="zorderHint"></span>
<div class="zdrag" id="zdrag">
  <div class="zchip" data-z="c" draggable="true"><span class="grip">⠿</span>InfoMercial</div>
  <div class="zchip" data-z="a" draggable="true"><span class="grip">⠿</span>Topic</div>
  <div class="zchip fixed" data-z="b"><span class="grip">▦</span>Content</div>
</div>
</div>
<div class="toolbar"><span style="font-size:10px;opacity:0.6" data-i18n="hint"></span></div>

<div id="metaFields" class="meta-fields" hidden>
<label><span data-i18n="fTitle"></span><input id="mTitle" maxlength="200" autocomplete="off"></label>
<label><span data-i18n="fTags"></span><input id="mTags" data-i18n-ph="tagsPh" autocomplete="off"></label>
<label><span data-i18n="fLength"></span><input id="mDur" data-i18n-ph="lenPh" inputmode="numeric" autocomplete="off"></label>
</div>
<div class="tabs" role="tablist">
<button class="tab" id="tabSheet" role="tab" aria-selected="true" data-i18n="tabSheet"></button>
<button class="tab" id="tabPics" role="tab" aria-selected="false" data-i18n="tabPics"></button>
</div>
<div class="ta-wrap pane" id="paneSheet"><textarea id="input"></textarea><div class="ta-bar" id="taBar"><div class="ta-thumb" id="taThumb"></div></div></div>
<div class="pane pane-pics" id="panePics" hidden>
  <div class="spr-box"><b data-i18n="sprTitle"></b><p class="hint" style="margin:0;font-size:11px;opacity:.7" data-i18n="sprHint"></p><div id="sprStrip"></div></div>
  <p class="hint" style="margin:0;font-size:11px;opacity:.7" data-i18n="picHint"></p>
  <div class="drop" id="drop"><span data-i18n="picDrop"></span><input type="file" id="picFile" accept="image/*,.glb,.gltf,.stl,.obj" multiple hidden></div>
  <div class="pics" id="pics"></div>
  <div class="toolbar" style="margin:0">
    <select id="picZone" class="tool-btn" style="padding:7px 10px">
      <option value="b" data-i18n="picZoneB"></option>
      <option value="a" data-i18n="picZoneA"></option>
      <option value="c" data-i18n="picZoneC"></option>
    </select>
    <button class="tool-btn primary" id="btnPicAdd" data-i18n="picAdd"></button>
    <button class="tool-btn" style="background:var(--yellow)" id="btnSprites" data-i18n="picSprites"></button>
    <button class="tool-btn" id="btnSpriteReset" data-i18n="spriteReset"></button>
  </div>
</div>
<div style="display:flex;justify-content:space-between;margin-top:10px"><span id="count"></span><div style="display:flex;gap:8px"><button class="tool-btn" onclick="closePanel()" data-i18n="cancel"></button><button class="tool-btn primary" onclick="batchImport()" data-i18n="push"></button></div></div>
</div>

<div id="wall" class="masonry"></div>
<div id="toast" class="toast" role="status" aria-live="polite"></div>

<!-- ===== 共用核心：資料正規化 + 卡片渲染。獨立牆匯出時原封不動複製這段，兩邊永遠一致 ===== -->
<script id="wall-core">
{{-- pass 4: shared wall-core (same file Vite bundles via ?raw for EzSheetPreview). Was an inline copy. --}}
{!! file_get_contents(resource_path('js/components/wall-core.js')) !!}
</script>

<!-- ===== 編輯器 ===== -->
<script>
const {GRID,MIN,YT,esc,httpUrl,normalize,makeCard,applySize,watchWidth,validCols,I18N,t,getLang,randomSize}=WallCore;
const $=id=>document.getElementById(id);
const wall=$('wall'),ta=$('input');
/* 頂部列的版面下拉與語言選單由核心產生，和獨立牆同一套 */
$('menuSlot').outerHTML=WallCore.menuHTML(false);
$('langSlot').outerHTML=WallCore.langSelectHTML();
$('shuffleSlot').outerHTML=WallCore.shuffleBtnHTML();
$('carSlot').outerHTML=WallCore.carouselBtnHTML();
$('filtSlot').outerHTML=WallCore.filterBtnHTML();
$('zoneSlot').outerHTML=WallCore.zoneBtnHTML();
$('fxSlot').outerHTML=WallCore.fxBtnHTML();
/* 匯出鍵放在 EzBar 上：推上牆之後就在預覽區直接複製／下載，
   而且帶走的就是此刻畫面上的設定 */
$('expSlot').outerHTML=
  '<button id="copyBtn" class="pill exp" data-i18n-aria="copyWall"><span class="mi">\ud83d\udccb</span></button>'
 +'<button id="dlBtn" class="pill exp" data-i18n-aria="dlWall"><span class="mi">\ud83d\udcbe</span></button>';
$('copyBtn').addEventListener('click',copyFullHTML);
$('dlBtn').addEventListener('click',downloadStandalone);
$('barZone').insertAdjacentHTML('beforeend',WallCore.filterBarHTML());
$('lang').addEventListener('change',e=>setLang(e.target.value));

/* ---------- 多語系：預設英文 ---------- */
function applyLang(){
  WallCore.applyI18n();
  ta.placeholder=t('placeholder')+'\nhttps://example.com\nhttps://www.youtube.com/watch?v=xxxxxxxxxxx\n<iframe src=...></iframe>';
  updateCount();WallCore.updateLayoutUI(cols);WallCore.applyZoneOrder(zorder);WallCore.carSync(wall,true);
}

function getWallId(){
  const DEF='一起維基';
  const m=location.href.match(/ez\.wiki\/([A-Za-z0-9]+)/);if(m)return m[1];
  /* about:srcdoc、about:blank、本機檔案、blob/data 預覽：路徑不是牆 ID，一律用預設名稱 */
  if(!/^https?:$/.test(location.protocol))return DEF;
  const p=location.pathname.split('/').filter(Boolean)[0]||'';
  /* 只接受純英數 ID；index.html 之類的檔名不算 */
  return /^[A-Za-z0-9_-]+$/.test(p)&&!/^(srcdoc|blank)$/i.test(p)?p:DEF;
}
const WALL_ID=getWallId();const KEY=`wiki_wall_${WALL_ID}`;
/* 舊版預設 ID 是 srcdoc：新 ID 還沒有資料時，自動搬過來（含欄位設定），不會遺失 */
if(WALL_ID==='一起維基'){try{
  const OLD='wiki_wall_srcdoc';
  if(localStorage.getItem(KEY)===null&&localStorage.getItem(OLD)!==null){
    localStorage.setItem(KEY,localStorage.getItem(OLD));
    const c=localStorage.getItem(OLD+'_cols');if(c!==null)localStorage.setItem(KEY+'_cols',c);
  }
}catch(e){}}
$('idDisplay').textContent=WALL_ID;$('idDisplay2').textContent=WALL_ID;

/* ---------- 儲存 ---------- */
function load(){try{const a=JSON.parse(localStorage.getItem(KEY)||'[]');return Array.isArray(a)?a.map(normalize).filter(Boolean):[];}catch(e){return [];}}
function save(){clearTimeout(saveT);saveT=0;try{localStorage.setItem(KEY,JSON.stringify(list));return true;}catch(e){toast(t('storageFull'));return false;}}
/* 排版、量高度這類頻繁的更新合併成一次寫入 */
let saveT=0;function saveSoon(){clearTimeout(saveT);saveT=setTimeout(save,400);}
addEventListener('pagehide',()=>{if(saveT)save();});
let list=load();

let toastT;
function toast(m){const el=$('toast');el.textContent=m;el.classList.add('show');clearTimeout(toastT);toastT=setTimeout(()=>el.classList.remove('show'),2800);}
/* 圖檔／3D 工作區還空著、牆上也沒有圖卡時，MagicBar 不擺特效鍵。
   pics 在這個函式之後才宣告，所以讀不到時就當作是空的。 */
function syncFx(){
  let queued=0;try{queued=pics.length;}catch(e){}
  WallCore.fxShow(WallCore.fxAvailable(list,queued>0));
}
function updateCount(){
  $('count').textContent=t('count',{n:list.length});
  syncFx();
  /* 推上牆之前不顯示匯出鍵 */
  document.body.classList.toggle('empty-wall',!list.length);
}

/* ---------- 解析 ---------- */
const SEP='━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━',ZSEP='==============================',CSEP='##############################';
const isSep=s=>/^[━─—\-]{3,}$/.test(s.trim());        /* 卡片之間 */
const isZone=s=>/^={3,}$/.test(s.trim());             /* A 區／B 區之間 */
const isCZone=s=>/^#{1,}$/.test(s.trim());            /* C 區（業配）與其下方之間 */
const ZMARK='\u0000zone',CMARK='\u0000czone';
const VOID=/^(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)$/i;
function tagDepth(s){let d=0,m;const re=/<(\/?)([a-zA-Z][\w-]*)\b[^>]*?(\/?)>/g;while((m=re.exec(s))){if(VOID.test(m[2])||m[3])continue;d+=m[1]?-1:1;}return d;}
function closed(s){return s.lastIndexOf('<')<=s.lastIndexOf('>')&&tagDepth(s)<=0;}

/* 把貼上的文字切成「一張卡一段」：網址一行一個；HTML 依標籤深度收集，多行嵌入碼不會被拆開 */
function splitBlocks(text){
  const out=[];let buf=null;
  let md=null;
  for(const rawLine of text.split(/\r?\n/)){
    const line=rawLine.trim();
    /* Markdown 卡是不透明區塊：--- === # 在裡面是 MD 語法，不是分隔線 */
    if(md!==null){md+='\n'+rawLine;if(/<\/md>\s*$/i.test(line)){out.push(md.trim());md=null;}continue;}
    if(buf===null&&/^<md>/i.test(line)){
      if(/<\/md>\s*$/i.test(line)&&line.length>4)out.push(line);else md=rawLine;
      continue;
    }
    if(buf!==null){
      if(isSep(line)||isZone(line)||isCZone(line)||(/^https?:\/\/\S+$/i.test(line)&&buf.lastIndexOf('<')<=buf.lastIndexOf('>'))){out.push(buf.trim());buf=null;}
      else{buf+='\n'+rawLine;if(closed(buf)){out.push(buf.trim());buf=null;}continue;}
    }
    if(isZone(line)){out.push(ZMARK);continue;}
    if(isCZone(line)){out.push(CMARK);continue;}
    if(!line||isSep(line))continue;
    if(line.startsWith('<')){
      /* Twitter/IG 類嵌入碼：下一行的 <script> 歸回上一個區塊 */
      if(/^<script\b/i.test(line)&&out.length&&out[out.length-1].startsWith('<'))buf=out.pop()+'\n'+rawLine;
      else buf=rawLine;
      if(closed(buf)){out.push(buf.trim());buf=null;}
      continue;
    }
    const found=line.match(/https?:\/\/[^\s"'<>]+/gi);
    if(found)out.push(...found);else out.push(line);
  }
  if(buf!==null)out.push(buf.trim());
  if(md!==null)out.push(md.trim());
  return out;
}

/* 和插卡對話框共用同一份判斷（核心裡的 parseOne） */
const makeItem=WallCore.parseOne;

function parseBatch(text){
  text=String(text||'').trim();if(!text)return {items:[],skipped:0};
  if(/^[\[{]/.test(text)){
    try{
      const obj=JSON.parse(text);
      const arr=Array.isArray(obj)?obj:(obj.list||obj.current||obj.data);
      if(Array.isArray(arr)){const items=arr.map(o=>normalize(o&&typeof o==='object'?{...o,_id:null}:null)).filter(Boolean);return {items,skipped:arr.length-items.length,cols:validCols(obj.cols)?obj.cols:undefined,car:typeof obj.car==='boolean'?obj.car:undefined,zones:obj.zones,zorder:obj.zorder};}
    }catch(e){}
  }
  const blocks=splitBlocks(text),items=[];let skipped=0;
  /* A boundary line labels the block ABOVE it, so the order of the markers does not matter:
       ###  -> everything since the previous marker is Zone C (infomercial)
       ===  -> everything since the previous marker is Zone A (top marquee)
     Whatever is left after the last marker is Zone B. */
  let buf=[];
  const flush=zone=>{buf.forEach(o=>{o.zone=zone;items.push(o);});buf=[];};
  blocks.forEach(b=>{
    if(b===CMARK){flush('c');return;}
    if(b===ZMARK){flush('a');return;}
    const it=makeItem(b);
    if(it)buf.push(it);else skipped++;
  });
  flush('b');
  return {items,skipped};
}

/* ---------- 渲染：保留原本的卡片節點，不讓 iframe 每次都重新載入 ---------- */
function render(){
  /* 既有節點永遠不搬動（iframe 不會重新載入），位置交給 layoutWall */
  /* #wall holds Zone B only; Zone A and Zone C nodes are managed by their band code */
  const B=list.filter(o=>o.zone==='b'||!o.zone),keep=new Set(B.map(o=>o._id));
  [...wall.children].forEach(el=>{if(!keep.has(el.dataset.id))WallCore.dropCard(el);});
  const have=new Map([...wall.children].map(el=>[el.dataset.id,el]));
  B.forEach(o=>{
    let el=have.get(o._id);
    if(!el){el=makeCard(o,false);wall.appendChild(el);}
    applySize(el,o);
  });
  updateCount();relayout();
  WallCore.checkFrames(list,rebuild);
  WallCore.fetchMeta(list,onMeta);
}

/* ---------- 面板 ---------- */
function openPanel(i){
  const mf=$('metaFields');mf.hidden=!(Number.isInteger(i)&&list[i]);
  if(Number.isInteger(i)&&list[i]){
    const o=list[i];ta.value=o._raw.trim();ta.dataset.editIndex=i;
    $('mTitle').value=o.title||'';$('mTags').value=(o.tags||[]).join(', ');$('mDur').value=WallCore.fmtDur(o.dur);
  }
  else{
    /* 全部列出時：A 區在上、B 區在下，中間用「===」隔開 */
    const j=a=>a.map(o=>o._raw.trim()).join('\n'+SEP+'\n'),
      C=list.filter(o=>o.zone==='c'),A=list.filter(o=>o.zone==='a'),B=list.filter(o=>o.zone==='b'||!o.zone);
    ta.value=(C.length?j(C)+'\n':'')+CSEP+'\n'+(A.length?j(A)+'\n':'')+ZSEP+'\n'
      +(B.length?j(B)+'\n'+SEP+'\n':'');
    delete ta.dataset.editIndex;
  }
  updateCount();$('panel').classList.remove('hidden');ta.focus();
  const end=ta.value.length;ta.setSelectionRange(end,end);ta.scrollTop=ta.scrollHeight;
  showTab(false);paintChips();
  if(!Number.isInteger(i))gotoZoneLine();
  requestAnimationFrame(taSync);
}

/* Enter：目前這段（網址或已收尾的嵌入碼）完成時，自動補一條分隔線。
   Shift+Enter 純換行；中文輸入法選字中不觸發；嵌入碼還沒收尾時照常換行 */
function insertText(txt,at){
  ta.focus();ta.setSelectionRange(at,at);
  let ok=false;try{ok=document.execCommand('insertText',false,txt);}catch(e){}
  if(!ok)ta.setRangeText(txt,at,at,'end');
}
ta.addEventListener('keydown',e=>{
  if(e.key!=='Enter'||e.shiftKey||e.ctrlKey||e.metaKey||e.altKey||e.isComposing||e.keyCode===229)return;
  if(ta.dataset.editIndex!==undefined&&ta.dataset.editIndex!=='')return;
  const v=ta.value,pos=ta.selectionStart;if(pos!==ta.selectionEnd)return;
  const ls=v.lastIndexOf('\n',pos-1)+1;let le=v.indexOf('\n',pos);if(le<0)le=v.length;
  if(!v.slice(ls,le).trim()||isSep(v.slice(ls,le))||isZone(v.slice(ls,le))||isCZone(v.slice(ls,le)))return;
  const lines=v.slice(0,le).split('\n'),seg=[];
  for(let k=lines.length-1;k>=0&&!isSep(lines[k])&&!isZone(lines[k])&&!isCZone(lines[k]);k--)seg.unshift(lines[k]);
  const s=seg.join('\n').trim();
  if(s.startsWith('<')&&!closed(s))return;
  e.preventDefault();
  /* 下一行已經是分隔線就直接跳過去，不重複加 */
  if(le<v.length){
    const ne=v.indexOf('\n',le+1),next=v.slice(le+1,ne<0?v.length:ne);
    if(isSep(next)||isZone(next)||isCZone(next)){if(ne<0)insertText('\n',v.length);else ta.setSelectionRange(ne+1,ne+1);return;}
  }
  insertText('\n'+SEP+'\n',le);
});
/* ---------- EzSheet 右下角拉把 ----------
   原生 resize 被隱藏捲軸的設定一起關掉了，而且在 flex 版面裡橫向拉沒有作用，
   所以自己畫一個：拖曳調整整個編輯區的高度，文字框與自訂捲軸一起長。 */
(function(){
  const box=document.querySelector('.ta-wrap'),ta=document.getElementById('input');
  if(!box||!ta)return;
  const KEY='wiki_ta_h',MINH=160;
  const maxH=()=>Math.max(MINH,Math.round(innerHeight*0.82));
  function setH(h,save){
    h=Math.max(MINH,Math.min(maxH(),Math.round(h)));
    box.style.setProperty('--ta-h',h+'px');
    box.classList.add('sized');
    if(save){try{localStorage.setItem(KEY,String(h));}catch(e){}}
    if(typeof taSync==='function')taSync();
    return h;
  }
  try{const s=parseInt(localStorage.getItem(KEY),10);if(s>=MINH)setH(s,false);}catch(e){}
  const grip=document.createElement('div');
  grip.className='ta-grip';grip.id='taGrip';grip.tabIndex=0;grip.setAttribute('role','separator');
  grip.setAttribute('aria-orientation','horizontal');
  grip.setAttribute('aria-label','Resize the editor');
  if(getComputedStyle(box).position==='static')box.style.position='relative';
  box.appendChild(grip);
  /* 自訂捲軸出現時把拉把往左讓開，不要壓在捲軸上 */
  const bar=box.querySelector('.ta-bar');
  if(bar){
    const place=()=>{grip.style.right=(bar.hidden?6:bar.offsetWidth+14)+'px';};
    new MutationObserver(place).observe(bar,{attributes:true,attributeFilter:['hidden']});
    place();addEventListener('resize',place);
  }
  let y0=0,h0=0,on=false;
  grip.addEventListener('pointerdown',e=>{
    on=true;y0=e.clientY;h0=box.getBoundingClientRect().height;
    try{grip.setPointerCapture(e.pointerId);}catch(err){}
    document.body.style.userSelect='none';e.preventDefault();
  });
  grip.addEventListener('pointermove',e=>{if(on)setH(h0+(e.clientY-y0),false);});
  const stop=()=>{if(!on)return;on=false;document.body.style.userSelect='';
    setH(box.getBoundingClientRect().height,true);};
  ['pointerup','pointercancel'].forEach(k=>grip.addEventListener(k,stop));
  /* 鍵盤也能調，雙擊回到預設高度 */
  grip.addEventListener('keydown',e=>{
    const step=e.shiftKey?60:12,h=box.getBoundingClientRect().height;
    if(e.key==='ArrowDown'){e.preventDefault();setH(h+step,true);}
    else if(e.key==='ArrowUp'){e.preventDefault();setH(h-step,true);}
    else if(e.key==='Home'){e.preventDefault();reset();}
  });
  function reset(){
    box.classList.remove('sized');box.style.removeProperty('--ta-h');
    try{localStorage.removeItem(KEY);}catch(e){}
    if(typeof taSync==='function')taSync();
  }
  grip.addEventListener('dblclick',e=>{e.preventDefault();reset();});
  addEventListener('resize',()=>{if(box.classList.contains('sized'))setH(box.getBoundingClientRect().height,false);});
})();

/* 打開面板時直接捲到 A／B 分界的「===」那行並選取它，一眼看到界線在哪 */
function gotoZoneLine(){
  const lines=ta.value.split('\n');
  let idx=-1,pos=0,at=0;
  for(let k=0;k<lines.length;k++){
    if(isZone(lines[k])){idx=k;at=pos;break;}
    pos+=lines[k].length+1;
  }
  if(idx<0)return;
  ta.setSelectionRange(at,at+lines[idx].length);
  const cs=getComputedStyle(ta);
  let lh=parseFloat(cs.lineHeight);
  if(!lh||Number.isNaN(lh))lh=parseFloat(cs.fontSize)*1.8;
  ta.scrollTop=Math.max(0,Math.round(idx*lh-ta.clientHeight/2+lh));
}
/* 自製捲軸：跟著輸入框同步，拖拉或點擊軌道都能捲動 */
const taBar=$('taBar'),taThumb=$('taThumb');
function taSync(){
  const vh=ta.clientHeight,sh=ta.scrollHeight;
  if(sh<=vh+2){taBar.hidden=true;return;}
  taBar.hidden=false;
  const trackH=taBar.clientHeight,th=Math.max(56,Math.round(trackH*vh/sh));
  const max=sh-vh,pos=max?Math.round((trackH-th)*(ta.scrollTop/max)):0;
  taThumb.style.height=th+'px';taThumb.style.top=pos+'px';
}
ta.addEventListener('scroll',taSync);ta.addEventListener('input',taSync);
addEventListener('resize',taSync);
taBar.addEventListener('pointerdown',e=>{
  const rect=taBar.getBoundingClientRect(),th=taThumb.offsetHeight;
  const trackH=taBar.clientHeight,max=ta.scrollHeight-ta.clientHeight;
  const onThumb=e.target===taThumb;
  const grab=onThumb?(e.clientY-taThumb.getBoundingClientRect().top):th/2;
  const to=y=>{
    const pos=Math.max(0,Math.min(trackH-th,y-rect.top-grab));
    ta.scrollTop=max*(trackH-th?pos/(trackH-th):0);taSync();
  };
  e.preventDefault();taBar.classList.add('drag');
  try{taBar.setPointerCapture(e.pointerId);}catch(err){}
  to(e.clientY);
  const mv=ev=>to(ev.clientY);
  const up=()=>{taBar.classList.remove('drag');taBar.removeEventListener('pointermove',mv);taBar.removeEventListener('pointerup',up);taBar.removeEventListener('pointercancel',up);};
  taBar.addEventListener('pointermove',mv);taBar.addEventListener('pointerup',up);taBar.addEventListener('pointercancel',up);
});
/* ---------- Images tab: drop pictures in, use them as cards or as flying sprites ---------- */
const SKEY=KEY+'_sprites';
let pics=[];
/* 飛行圖示一律是這面牆自己的清單：一開始放內建的兩隻蜜蜂，可以換、可以刪 */
window.WALL_SPRITES=WallCore.SPRITE_DEF.slice();
try{const sp=WallCore.validSprites(JSON.parse(localStorage.getItem(SKEY)||'null'));if(sp)window.WALL_SPRITES=sp;}catch(e){}
function paintPics(){
  const box=$('pics');
  syncFx();
  box.innerHTML=pics.length?pics.map((p,i)=>{
    /* 3D 檔案只能當卡片，不能當飛行圖示，所以不給下拉選單 */
    const head=p.kind==='model'
      ? '<div class="m3d"><b>\u25c8</b><span>'+esc(p.ext.toUpperCase())+'</span><i>'+esc(p.name)+'</i></div>'
      : '<img src="'+p.data+'" alt="">';
    const pick=p.kind==='model'
      ? '<select disabled><option>'+esc(t('asCard'))+'</option></select>'
      : '<select data-i="'+i+'">'
        +'<option value="card"'+(p.use==='card'?' selected':'')+'>'+esc(t('asCard'))+'</option>'
        +'<option value="sprite"'+(p.use==='sprite'?' selected':'')+'>'+esc(t('asSprite'))+'</option>'
        +'</select>';
    return '<div class="pic">'+head+'<div class="row">'+pick
      +'<button data-del="'+i+'" aria-label="'+esc(t('del'))+'">✕</button></div></div>';
  }).join('')
    :'<div style="font-size:11px;opacity:.55;font-weight:800;padding:8px">'+esc(t('picNone'))+'</div>';
}
/* 圖檔與 3D 模型都是整個內嵌進牆面，所以太大的檔案會塞爆 localStorage */
const PIC_MAX=12*1024*1024;
function addPicFiles(files){
  let big=0,bad=0;
  [...files].forEach(f=>{
    const img=/^image\//.test(f.type),mdl=WallCore.isModelFile(f);
    if(!img&&!mdl){bad++;return;}
    if(f.size>PIC_MAX){big++;return;}
    const r=new FileReader();
    r.onload=()=>{
      const rec={name:f.name,data:String(r.result),use:'card'};
      if(mdl){rec.kind='model';rec.ext=WallCore.modelExt(f.name);}
      pics.push(rec);paintPics();
    };
    r.readAsDataURL(f);
  });
  if(big)toast(t('picTooBig',{n:big}));
  else if(bad)toast(t('picBadType',{n:bad}));
}
function showTab(pics_on){
  $('paneSheet').hidden=pics_on;$('panePics').hidden=!pics_on;
  $('tabSheet').setAttribute('aria-selected',!pics_on);$('tabPics').setAttribute('aria-selected',pics_on);
  if(!pics_on)requestAnimationFrame(taSync);
}
$('tabSheet').addEventListener('click',()=>showTab(false));
$('tabPics').addEventListener('click',()=>{paintPics();showTab(true);});
$('pics').addEventListener('change',e=>{const sel=e.target.closest('select');if(sel)pics[+sel.dataset.i].use=sel.value;});
$('pics').addEventListener('click',e=>{const b=e.target.closest('[data-del]');if(b){pics.splice(+b.dataset.del,1);paintPics();}});
$('drop').addEventListener('click',()=>$('picFile').click());
$('picFile').addEventListener('change',e=>{addPicFiles(e.target.files);e.target.value='';});
['dragenter','dragover'].forEach(k=>$('drop').addEventListener(k,e=>{e.preventDefault();$('drop').classList.add('over');}));
['dragleave','drop'].forEach(k=>$('drop').addEventListener(k,e=>{e.preventDefault();$('drop').classList.remove('over');}));
$('drop').addEventListener('drop',e=>addPicFiles(e.dataTransfer.files));
/* Add every "as card" image to the wall as an HTML card */
$('btnPicAdd').addEventListener('click',()=>{
  const zone=$('picZone').value,now=Date.now(),made=[];
  pics.filter(p=>p.use==='card').forEach(p=>{
    let o;
    if(p.kind==='model'){
      o=normalize({type:'model',mdl:{d:p.data,e:p.ext,n:p.name},zone,
        w:WallCore.MODEL_W,h:WallCore.MODEL_H});
    }else{
      const html='<div style="margin:0;background:#fff"><img src="'+p.data+'" alt="'+esc(p.name)+'" style="display:block;width:100%;height:auto"></div>';
      o=normalize({_raw:html,type:'html',html,zone,w:340,h:260,pic:true});
    }
    if(o){o.at=now;o.title=o.title||p.name;made.push(o);}
  });
  if(!made.length)return;
  list=list.concat(made);pics=pics.filter(p=>p.use!=='card');
  save();render();paintPics();toast(t('picAdded',{n:made.length}));
});
/* Replace the flying sprites with the "as sprite" images */
function applySprites(arr){
  window.WALL_SPRITES=WallCore.validSprites(arr)||WallCore.SPRITE_DEF.slice();
  try{localStorage.setItem(SKEY,JSON.stringify(window.WALL_SPRITES));}catch(e){}
  /* 正在飛的話重新放飛，新圖才會用上；關著就維持關著 */
  WallCore.fxPaint();
  if(WallCore.fxState().fly){WallCore.fxSetFly(false);setTimeout(()=>WallCore.fxSetFly(true),80);}
}
const sprUI=WallCore.spriteStrip($('sprStrip'),()=>window.WALL_SPRITES,applySprites);
addEventListener('change',e=>{if(e.target&&e.target.id==='lang')setTimeout(()=>sprUI.paint(),0);});
$('btnSprites').addEventListener('click',()=>{
  const arr=pics.filter(p=>p.use==='sprite'&&p.kind!=='model').map(p=>p.data).slice(0,4);
  if(!arr.length)return;
  applySprites(arr);pics=pics.filter(p=>p.use!=='sprite');paintPics();sprUI.paint();toast(t('spriteOk',{n:arr.length}));
});
$('btnSpriteReset').addEventListener('click',()=>{applySprites(null);sprUI.paint();toast(t('spriteReset'));});

/* ---------- Zone order: drag the C and A chips in the panel ----------
   B always stays at the bottom with the control bar above it, so only the two top bands move.
   The order applies straight away and is saved with the wall. */
function paintChips(){
  const box=$('zdrag');
  const chips=WallCore.ZONES.filter(z=>z!=='b').map(z=>box.querySelector('.zchip[data-z="'+z+'"]'));
  zorder.filter(z=>z!=='b').forEach((z,i)=>{const el=box.querySelector('.zchip[data-z="'+z+'"]');if(el)el.style.order=i;});
  box.querySelector('.zchip[data-z="b"]').style.order=9;
}
function setOrder(arr){
  zorder=WallCore.validOrder(arr);
  WallCore.applyZoneOrder(zorder);paintChips();saveZones();
}
(function dragChips(){
  const box=$('zdrag');let src=null;
  box.addEventListener('dragstart',e=>{
    const c=e.target.closest('.zchip:not(.fixed)');if(!c){e.preventDefault();return;}
    src=c;c.classList.add('drag');e.dataTransfer.effectAllowed='move';
    try{e.dataTransfer.setData('text/plain',c.dataset.z);}catch(err){}
  });
  box.addEventListener('dragover',e=>{
    const c=e.target.closest('.zchip:not(.fixed)');if(!c||c===src)return;
    e.preventDefault();c.classList.add('over');
  });
  box.addEventListener('dragleave',e=>{const c=e.target.closest('.zchip');if(c)c.classList.remove('over');});
  box.addEventListener('drop',e=>{
    const c=e.target.closest('.zchip:not(.fixed)');if(!c||!src||c===src)return;
    e.preventDefault();c.classList.remove('over');
    setOrder([c.dataset.z===zorder[0]?src.dataset.z:c.dataset.z,c.dataset.z===zorder[0]?c.dataset.z:src.dataset.z,'b']);
  });
  box.addEventListener('dragend',()=>{box.querySelectorAll('.zchip').forEach(c=>c.classList.remove('drag','over'));src=null;});
  /* Touch: a tap on either chip swaps the two bands */
  box.addEventListener('click',e=>{
    const c=e.target.closest('.zchip:not(.fixed)');if(!c)return;
    const top=zorder.filter(z=>z!=='b');
    setOrder([top[1],top[0],'b']);
  });
})();
function closePanel(){$('panel').classList.add('hidden');}
function setLang(l){l=WallCore.setLangCode(l);try{localStorage.setItem('wiki_lang',l);}catch(e){}$('lang').value=l;applyLang();}

function batchImport(){
  const r=parseBatch(ta.value),{items,skipped}=r;
  if(!items.length){toast(t('noInput'));return;}
  const idx=ta.dataset.editIndex;
  if(idx!==undefined&&idx!==''){
    const i=parseInt(idx,10),old=list[i];
    const now=Date.now();items.forEach(o=>{o.at=now;});
    if(old){
      /* 單張編輯：沿用尺寸與加入時間，標題／標籤／長度取自欄位 */
      Object.assign(items[0],{w:old.w,h:old.h,at:old.at||now,pub:old.pub,span:old.span,rh:old.rh,emb:old.emb,blk:old.blk,zone:old.zone});
      const tt=$('mTitle').value.trim(),tg=$('mTags').value.split(',').map(x=>x.trim().replace(/^#/,'')).filter(Boolean),du=WallCore.parseDur($('mDur').value);
      if(tt)items[0].title=tt.slice(0,200);else delete items[0].title;
      if(tg.length)items[0].tags=tg.slice(0,20);else delete items[0].tags;
      if(du)items[0].dur=du;else delete items[0].dur;
      list.splice(i,1,...items);
    }else list.push(...items);
  }else{
    /* 依 _raw 對回原本的尺寸與節點，推上牆不再把排版洗掉 */
    const pool=new Map();
    list.forEach(o=>{if(!pool.has(o._raw))pool.set(o._raw,[]);pool.get(o._raw).push(o);});
    /* 沿用舊卡的尺寸、加入時間、標題等；新卡記下加入時間 */
    const now=Date.now(),KEEP=['_id','w','h','at','pub','title','tags','dur','emb','blk','span','rh','manual','mh'];
    list=items.map(o=>{const q=pool.get(o._raw),old=q&&q.shift();if(!old){if(!o.at)o.at=now;return o;}const n={...o};KEEP.forEach(k=>{if(old[k]!==undefined)n[k]=old[k];});return n;});
  }
  list.forEach(o=>{o.fit=WallCore.fitOf(o);});
  if(r.cols!==undefined)setCols(r.cols);if(r.car!==undefined&&r.car!==car)setCar(r.car);
  if(r.zones){zones=WallCore.validZones(r.zones);saveZones();WallCore.zonePaint(zones);}
  if(r.zorder){zorder=WallCore.validOrder(r.zorder);WallCore.applyZoneOrder(zorder);saveZones();}
  save();render();closePanel();
  if(skipped)toast(t('skipped',{n:skipped}));
}

/* ---------- 卡片操作（事件委派） ---------- */
wall.addEventListener('click',e=>{
  const b=e.target.closest('[data-act]');if(!b)return;
  const el=b.closest('.item'),i=list.findIndex(x=>x._id===el.dataset.id);if(i<0)return;
  if(b.dataset.act==='ins'){
    /* 插卡：在這張卡前面插一張新的，存進版主自己的牆 */
    e.preventDefault();
    WallCore.insertDialog(false,o=>{
      o.zone=list[i].zone||'b';o.at=Date.now();
      const anchor=WallCore.rawHash?WallCore.rawHash(list[i]._raw):null;
      const userId = (window.auth && window.auth.user) ? window.auth.user.id : null;
      return fetch((typeof syncBase==='function'?syncBase(WALL_ID):'/api/wall/'+encodeURIComponent(WALL_ID))+'/cards',{
        method:'POST',
        headers:{'content-type':'application/json','x-wall-me':WallCore.wallMe?WallCore.wallMe():'owner','x-wall-owner':WallCore.ownerToken?WallCore.ownerToken():''},
        body:JSON.stringify({raw:o._raw,anchor,zone:o.zone,ok:true,user_id:userId})
      }).then(()=>{
        list.splice(i,0,o);save();render();toast(t('insDone'));
        return true;
      }).catch(e=>{
        console.warn('DB save err',e);
        list.splice(i,0,o);save();render();toast(t('insDone'));
        return true;
      });
    });
    return;
  }
  if(b.dataset.act==='del'||b.dataset.act==='gdel'){list.splice(i,1);save();WallCore.dropCard(el);updateCount();relayout();}
  else if(b.dataset.act==='emb'){
    /* 墓碑 ↔ 嵌入 切換：只重建這一張卡 */
    const o=list[i];o.emb=WallCore.isTomb(o)?'yes':'no';rebuild(o);
  }
  else openPanel(i);
});

/* A／C 區（走馬燈）上的插卡鍵與刪除鍵：那兩區的卡片不在 #wall 裡，要另外接。
   它們的節點 id 前面多了 a-／c-，對回原本的卡片才找得到位置。 */
document.addEventListener('click',e=>{
  const delBtn=e.target.closest('[data-act="del"],[data-act="gdel"]');
  if(delBtn&&delBtn.closest('#zoneA,#zoneC')){
    const el=delBtn.closest('.item');if(!el)return;
    const id=String(el.dataset.id).replace(/^[ac]-/,'');
    const i=list.findIndex(x=>x._id===id);if(i<0)return;
    e.preventDefault();
    list.splice(i,1);save();WallCore.dropCard(el);updateCount();relayout();
    return;
  }
  const btn=e.target.closest('[data-act="ins"]');if(!btn)return;
  if(!btn.closest('#zoneA,#zoneC'))return;          /* #wall 那邊已經有自己的處理 */
  const el=btn.closest('.item');if(!el)return;
  const id=String(el.dataset.id).replace(/^[ac]-/,'');
  const i=list.findIndex(x=>x._id===id);if(i<0)return;
  e.preventDefault();
  const doZoneInsert = () => {
    WallCore.insertDialog(false,o=>{
      o.zone=list[i].zone||'b';o.at=Date.now();
      const anchor=WallCore.rawHash?WallCore.rawHash(list[i]._raw):null;
      const userId = (window.auth && window.auth.user) ? window.auth.user.id : null;
      return fetch((typeof syncBase==='function'?syncBase(WALL_ID):'/api/wall/'+encodeURIComponent(WALL_ID))+'/cards',{
        method:'POST',
        headers:{'content-type':'application/json','x-wall-me':WallCore.wallMe?WallCore.wallMe():'owner','x-wall-owner':WallCore.ownerToken?WallCore.ownerToken():''},
        body:JSON.stringify({raw:o._raw,anchor,zone:o.zone,ok:true,user_id:userId})
      }).then(()=>{
        list.splice(i,0,o);save();render();toast(t('insDone'));
        return true;
      }).catch(e=>{
        console.warn('DB save err',e);
        list.splice(i,0,o);save();render();toast(t('insDone'));
        return true;
      });
    });
  };
  const auth = (typeof window !== 'undefined' ? window.auth : null) || {};
  if (!auth?.user) {
    if (typeof showWallOtpModal === 'function') {
      showWallOtpModal(doZoneInsert);
    } else {
      doZoneInsert();
    }
  } else {
    doZoneInsert();
  }
});

/* Pointer Events + capture：拖過其他 iframe 也不會斷，滑鼠與觸控共用 */
/* pass 4: drag-to-position removed (rule 6.4); only resize stays */
wall.addEventListener('pointerdown',e=>{
  const a=e.target.closest('.resize-anchor,.side-anchor');if(!a)return;
  const el=a.closest('.item'),o=list.find(x=>x._id===el.dataset.id);if(!o)return;
  /* 調整大小的數學在核心裡，和獨立牆共用同一份 */
  WallCore.resizeStart(a,el,o,a.classList.contains('side-anchor'),e,()=>{
    if(cols)setCols(0);save();relayout();
  });
});

/* ---------- 版面：0＝自由，1~4＝欄位（每面牆各自記住） ---------- */
const LKEY=KEY+'_cols',CKEY=KEY+'_car',ZKEY=KEY+'_zones';
/* Zone switches. The current state is also the default stored in an exported wall. */
let zones={a:true,b:true,c:true},zorder=WallCore.ZORDERS[0].slice();
try{const z=JSON.parse(localStorage.getItem(ZKEY)||'null');if(z&&z.zones)zones=WallCore.validZones(z.zones);else if(z)zones=WallCore.validZones(z);
    if(z&&z.order)zorder=WallCore.validOrder(z.order);}catch(e){}
function saveZones(){try{localStorage.setItem(ZKEY,JSON.stringify({zones,order:zorder}));}catch(e){}}
let car=false;try{car=localStorage.getItem(CKEY)==='1';}catch(e){}
function setCar(on){car=on;try{localStorage.setItem(CKEY,on?'1':'0');}catch(e){}WallCore.setCarousel(wall,on);relayout();WallCore.playFX(wall,120);}
$('carBtn').addEventListener('click',()=>setCar(!car));
let cols=0;try{const c=parseInt(localStorage.getItem(LKEY),10);if(validCols(c))cols=c;}catch(e){}
function setCols(n){cols=n;try{localStorage.setItem(LKEY,String(n));}catch(e){}WallCore.updateLayoutUI(n);}
WallCore.bindZones(zones,()=>{saveZones();relayout();});
WallCore.applyZoneOrder(zorder);
WallCore.bindMenu(n=>{
  if(n==='reset'){resetPositions();return;}
  if(n>0)columnLayout(n);else randomLayout();
});
/* 開啟選單時把自訂框補成目前的欄數 */
WallCore.updateLayoutUI(cols);
/* 一鍵復位：清掉所有手動位置，回到目前版面自動排列 */
function resetPositions(){
  list.forEach(o=>{delete o.free;delete o.px;delete o.py;});
  save();relayout();WallCore.playFX(wall,120);
}
function rebuild(o){
  o.fit=WallCore.fitOf(o);
  if(!o.span&&!cols)o.h=WallCore.fitH(o,o.w,2);
  const el=wall.querySelector('.item[data-id="'+o._id+'"]');if(el)WallCore.dropCard(el);
  save();render();
}
/* 伺服器嵌入檢查（有 ez.wiki 端點時填入網址即可啟用） */
WallCore.setFrameCheck('');
const F={...WallCore.FDEF};
addEventListener('wall:relayout',()=>relayout());
function relayout(){
  const v=WallCore.applyView(wall,list,F);
  const A=zones.a?v.filter(o=>o.zone==='a'):[],
        B=zones.b?v.filter(o=>o.zone==='b'||!o.zone):[],
        C=zones.c?v.filter(o=>o.zone==='c'):[];
  WallCore.zoneCUpdate(C);WallCore.zoneAUpdate(A);
  WallCore.zonePaint(zones,WallCore.zoneCounts(list));
  wall.hidden=!zones.b;
  if(!B.length){wall.style.height='';return;}
  WallCore.layoutWall(wall,B,cols,car);if(cols)saveSoon();
}
const fbar=WallCore.bindFilter(wall,F,relayout);
/* 自動補標題／長度（Spotify、Vimeo）；有 ez.wiki 端點時 setMetaFetch 填網址即可補所有網站 */
WallCore.setMetaFetch('');
let metaT=0;const onMeta=()=>{clearTimeout(metaT);metaT=setTimeout(()=>{save();relayout();},300);};
function columnLayout(n){setCols(n);relayout();WallCore.playFX(wall,120);}
watchWidth(wall,relayout);
/* HTML 嵌入碼回報實際高度 → 更新卡片高度（手動拉過的不動；一直長高的內容最多追 4 次） */
let mraf=0;const grow=new Map();
WallCore.onMeasure((id,h)=>{
  const o=list.find(x=>x._id===id);if(!o||o.manual||Math.abs((o.mh||0)-h)<=2)return;
  if(o.mh&&h>o.mh){const g=(grow.get(id)||0)+1;grow.set(id,g);if(g>4)return;}
  o.mh=h;o.h=WallCore.fitH(o,o.w,cols||2);
  if(!mraf)mraf=requestAnimationFrame(()=>{mraf=0;relayout();saveSoon();});
});

function randomLayout(){
  setCols(0);
  WallCore.playFX(wall,120);
  list=list.map(randomSize);
  save();render();
}
async function shuffleWall(){
  if(list.length<2)return;
  if(F.sort!=='wall'){F.sort='wall';fbar.sync();}
  const next=WallCore.shuffled(list);
  await WallCore.reorderAnimated(wall,next,()=>{list=next;save();relayout();});
  WallCore.playFX(wall,120);
}
$('shuffleBtn').addEventListener('click',shuffleWall);
function clearWall(){
  if(!confirm(t('confirmClear')))return;
  list=[];
  save();
  const targetWallId = window.WALL_ID || 'W0000001';
  fetch('/api/wall/' + encodeURIComponent(targetWallId) + '/clear', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ wall_id: targetWallId })
  }).catch(()=>{});
  const wallEl = $('wall');
  if(wallEl) wallEl.innerHTML = '';
  if(typeof WallCore.zoneAUpdate === 'function') WallCore.zoneAUpdate([]);
  if(typeof WallCore.zoneCUpdate === 'function') WallCore.zoneCUpdate([]);
  render();
  toast('✓ All cards under wall ' + targetWallId + ' deleted from database. Wall is now blank.');
}

/* ---------- 匯出 ---------- */
function exportItem(o){const r={_raw:o._raw,type:o.type,w:o.w,h:o.h};if(o.mh)r.mh=o.mh;if(o.manual)r.manual=true;if(o.zone==='a'||o.zone==='c')r.zone=o.zone;if(o.span){r.span=o.span;r.rh=o.rh;}if(o.emb)r.emb=o.emb;if(o.blk)r.blk=true;if(o.pic)r.pic=true;['at','pub','title','tags','dur'].forEach(k=>{if(o[k]!==undefined)r[k]=o[k];});if(o.type==='youtube'){r.id=o.id;r.url=o.url;}else if(o.type==='model'){r.mdl=o.mdl;}else if(o.type==='html'){r.html=o.html;}else if(o.type==='md'){}else{r.url=o.url;}return r;}
function downloadText(text,type,name){
  const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([text],{type}));a.download=name;
  document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),1500);
}
function downloadJSON(){downloadText(JSON.stringify({id:WALL_ID,count:list.length,cols,car,zones,zorder,list:list.map(exportItem)},null,2),'application/json',WALL_ID+'-random.json');}

/* 獨立牆 = 共用 CSS + 共用核心 + 資料。資料裡的 < 轉成 \u003c，內容含結束 script 標籤也不會截斷 */
function buildStandalone(){
  const css=$('wall-css').textContent;
  const core=$('wall-core').textContent;
  /* 匯出的預設值＝此刻 EzBar 上的設定：版面、輪播、區塊開關與順序、
     語言、煙火／飛行物／音效，以及篩選列（開著的話連條件一起帶走） */
  const fOpen=!!(fbar&&fbar.open&&fbar.open());
  const data=JSON.stringify({id:WALL_ID,count:list.length,cols,car,zones,zorder,lang:getLang(),
    fx:WallCore.fxState(),
    filter:fOpen||list.length>WallCore.FILTER_MIN,
    fset:fOpen?{q:F.q,type:F.type,date:F.date,sort:F.sort}:undefined,
    list:list.map(exportItem)})
    .replace(/</g,'\\u003c').replace(/\u2028/g,'\\u2028').replace(/\u2029/g,'\\u2029');
  return '<!DOCTYPE html>\n<html lang="'+getLang()+'"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>'+esc(WALL_ID)+'</title>'
    +'<style>'+css+'</style></head><body class="ro"><div id="wall" class="masonry"></div>'
    +'<script id="wall-data" type="application/json">'+data+'<\/script>'
    +'<script>window.WALL_SPRITES='+JSON.stringify(WallCore.validSprites(window.WALL_SPRITES)||[]).replace(/</g,'\\u003c')+';<\/script>'
    +'<script>'+core+'<\/script><script>WallCore.bootReadonly();<\/script></body></html>';
}
function legacyCopy(text){
  const t=document.createElement('textarea');t.value=text;t.setAttribute('readonly','');t.style.cssText='position:fixed;left:-9999px;top:0';
  document.body.appendChild(t);t.select();let ok=false;try{ok=document.execCommand('copy');}catch(e){}t.remove();return ok;
}
async function copyFullHTML(){
  if(!list.length){toast(t('emptyWall'));return;}
  const html=buildStandalone();
  try{await navigator.clipboard.writeText(html);toast(t('copied',{n:list.length}));return;}catch(e){}
  if(legacyCopy(html)){toast(t('copied',{n:list.length}));return;}
  downloadText(html,'text/html',WALL_ID+'-wall.html');toast(t('clipFallback'));
}
function downloadStandalone(){
  if(!list.length){toast(t('emptyWall'));return;}
  downloadText(buildStandalone(),'text/html',WALL_ID+'-wall.html');toast(t('downloaded',{n:list.length}));
}

/* ---------- 上傳：圖片、PDF、HTML、影音、JSON 儲存到 ezsheet 資料夾並呈現預覽 ---------- */
$('fileUpload').addEventListener('change',async function(e){
  const files=Array.from(e.target.files||[]);if(!files.length)return;
  this.value='';
  const addedCards=[];
  for(const file of files){
    const name=file.name;
    const ext=(name.split('.').pop()||'').toLowerCase();
    const type=file.type||'';

    if(ext==='json'||(type==='application/json'&&!type.startsWith('video/')&&!type.startsWith('audio/'))){
      try{
        let txt=await file.text();
        const m=txt.match(/<script id="wall-data" type="application\/json">([\s\S]*?)<\/script>/);if(m)txt=m[1];
        const res=parseBatch(txt),{items,skipped}=res;
        if(items.length){
          list=items;if(res.cols!==undefined)setCols(res.cols);if(res.car!==undefined&&res.car!==car)setCar(res.car);
          if(res.zones){zones=WallCore.validZones(res.zones);saveZones();WallCore.zonePaint(zones);}
          if(res.zorder){zorder=WallCore.validOrder(res.zorder);WallCore.applyZoneOrder(zorder);saveZones();}
          save();render();closePanel();
          const targetWallId = res.id || window.WALL_ID || 'W0000001';
          fetch('/api/wall/' + encodeURIComponent(targetWallId) + '/push-to-wall', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ wall_id: targetWallId, items: items, raw: txt })
          }).catch(()=>{});
          toast('✓ Old wall cards deleted. Loaded & inserted ' + items.length + ' cards for wall ' + targetWallId);
          continue;
        }
      }catch(err){}
    }

    let fileUrl='';
    try{
      const base64Data=await new Promise((resolve,reject)=>{
        const reader=new FileReader();
        reader.onload=()=>resolve(String(reader.result));
        reader.onerror=reject;
        reader.readAsDataURL(file);
      });
      const uploadRes=await fetch('/api/wall/upload-ezsheet-file',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({filename:name,data:base64Data,mime_type:type})
      });
      if(uploadRes.ok){
        const resData=await uploadRes.json();
        if(resData&&resData.url)fileUrl=resData.url;
      }
    }catch(upErr){}

    if(!fileUrl){
      try{
        fileUrl=await new Promise((resolve,reject)=>{
          const reader=new FileReader();
          reader.onload=()=>resolve(String(reader.result));
          reader.onerror=reject;
          reader.readAsDataURL(file);
        });
      }catch(err){fileUrl=URL.createObjectURL(file);}
    }

    let htmlPreview='',cardW=340,cardH=260,isPic=false;
    if(type.startsWith('image/')||['png','jpg','jpeg','gif','webp','svg','bmp','ico'].includes(ext)){
      isPic=true;cardW=340;cardH=260;
      htmlPreview='<div style="margin:0;background:#fff;display:flex;align-items:center;justify-content:center;min-height:160px;overflow:hidden;border-radius:8px"><img src="'+fileUrl+'" alt="'+WallCore.esc(name)+'" style="display:block;width:100%;height:auto;max-height:100%;object-fit:contain" /></div>';
    }else if(type==='application/pdf'||ext==='pdf'){
      cardW=380;cardH=340;
      htmlPreview='<div style="margin:0;background:#f8fafc;width:100%;height:100%;display:flex;flex-direction:column;overflow:hidden;border-radius:8px"><div style="background:#1e293b;color:#fff;padding:8px 12px;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:space-between"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:75%">📄 '+WallCore.esc(name)+'</span><a href="'+fileUrl+'" target="_blank" rel="noopener noreferrer" style="color:#38bdf8;text-decoration:none;font-size:11px;font-weight:700">Open ↗</a></div><iframe src="'+fileUrl+'#toolbar=0" style="flex:1;width:100%;min-height:280px;border:none;background:#fff" title="'+WallCore.esc(name)+'"></iframe></div>';
    }else if(type==='text/html'||ext==='html'||ext==='htm'){
      cardW=420;cardH=320;
      htmlPreview='<div style="margin:0;background:#fff;width:100%;height:100%;overflow:hidden;position:relative;border-radius:8px"><div style="background:#334155;color:#fff;padding:6px 10px;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:space-between"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:80%">🌐 '+WallCore.esc(name)+'</span><a href="'+fileUrl+'" target="_blank" rel="noopener noreferrer" style="color:#67e8f9;text-decoration:none;font-size:10px">Open ↗</a></div><iframe src="'+fileUrl+'" style="width:100%;height:calc(100% - 28px);min-height:260px;border:none" sandbox="allow-scripts allow-same-origin allow-forms" title="'+WallCore.esc(name)+'"></iframe></div>';
    }else if(type.startsWith('video/')||['mp4','webm','ogg','mov','m4v'].includes(ext)){
      cardW=360;cardH=280;
      htmlPreview='<div style="margin:0;background:#000;width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;overflow:hidden;border-radius:8px"><video src="'+fileUrl+'" controls playsinline style="width:100%;height:auto;max-height:calc(100% - 26px);background:#000" preload="metadata"></video><div style="width:100%;background:#09090b;color:#a1a1aa;padding:4px 8px;font-size:11px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">🎬 '+WallCore.esc(name)+'</div></div>';
    }else if(type.startsWith('audio/')||['mp3','wav','ogg','m4a','aac','flac'].includes(ext)){
      cardW=340;cardH=180;
      htmlPreview='<div style="margin:0;background:linear-gradient(135deg,#0f172a,#1e1b4b);color:#fff;width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;border-radius:8px"><div style="font-size:32px;margin-bottom:6px">🎵</div><div style="font-size:12px;font-weight:700;color:#e2e8f0;margin-bottom:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:90%">'+WallCore.esc(name)+'</div><audio src="'+fileUrl+'" controls style="width:100%;max-width:280px" preload="metadata"></audio></div>';
    }else{
      cardW=340;cardH=200;
      htmlPreview='<div style="margin:0;background:#f1f5f9;color:#334155;width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;border-radius:8px"><div style="font-size:28px;margin-bottom:6px">📁</div><div style="font-size:12px;font-weight:700;margin-bottom:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:90%">'+WallCore.esc(name)+'</div><a href="'+fileUrl+'" download="'+WallCore.esc(name)+'" style="font-size:11px;color:#2563eb;text-decoration:none;font-weight:600">Download File ⬇</a></div>';
    }

    const newCard=WallCore.normalize({
      _raw:htmlPreview,
      type:'html',
      html:htmlPreview,
      zone:'b',
      w:cardW,
      h:cardH,
      pic:isPic,
      title:name,
      at:Date.now()
    });
    if(newCard)addedCards.push(newCard);
  }

  if(addedCards.length){
    list=list.concat(addedCards);
    const ta=$('input');
    if(ta){
      const snippet=addedCards.map(c=>c._raw).join('\n'+SEP+'\n');
      ta.value=ta.value.trim()?ta.value.trim()+'\n'+SEP+'\n'+snippet:snippet;
    }
    save();render();
    if(WallCore.playFX)WallCore.playFX(wall,120);
    toast('Uploaded '+addedCards.length+' file(s) to ezsheet folder with live preview on wall!');
  }
});

document.addEventListener('keydown',e=>{
  if($('panel').classList.contains('hidden'))return;
  if(e.key==='Escape')closePanel();
  else if(e.key==='Enter'&&(e.ctrlKey||e.metaKey)){e.preventDefault();batchImport();}
});

(function init(){let l=null;try{l=localStorage.getItem('wiki_lang');}catch(e){}setCols(cols);WallCore.setCarousel(wall,car);WallCore.zoneAInit($('barZone'));WallCore.zoneCInit($('zoneA'));setLang(I18N[l]?l:'en');render();
  WallCore.verBadge();WallCore.fxInit(wall);})();
</script></body></html>
