/* Shared EzSheet editor: one implementation for the wall EzBar editor (EzSheetPreview srcdoc)
   and the ez.wiki homepage composer (SocialMediaComposer, EzSheet mode).
   Plain script with no imports: the wall srcdoc injects this file as-is (?raw), and React
   evaluates the same source once (see EzSheetEditorPanel.tsx). It defines window.EzSheetEditor.
   Pieces: the hint line, the EzSheet / Images & 3D tabs, the filter box with the === / ### chips,
   the textarea with the custom scroll bar, the item count, and Cancel / Push.
   Never put a closing script tag in this file (it is inlined into a <script> element). */
(function(G){
'use strict';
var SEP='━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━',ZSEP='==============================',CSEP='##############################';
var isSep=function(s){ return /^[━─—\-]{3,}$/.test(String(s).trim()); };
/* same boundary rules as the server parser: a line starting with === or ### */
var isZone=function(s){ return /^={3,}/.test(String(s).trim()); };
var isCZone=function(s){ return /^#{3,}/.test(String(s).trim()); };
var VOID=/^(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)$/i;
function tagDepth(s){
  var d=0,m;
  var re=/<(\/?)([a-zA-Z][\w-]*)\b[^>]*?(\/?)/g;
  while((m=re.exec(s))){
    if(VOID.test(m[2])||m[3]) continue;
    d+=m[1]?-1:1;
  }
  return d;
}
function closed(s){ return s.lastIndexOf('<')<=s.lastIndexOf('>') && tagDepth(s)<=0; }
function esc(t){ return String(t==null?'':t).replace(/[&<>"']/g,function(c){ return '&#'+c.charCodeAt(0)+';'; }); }

var DEF={
  hint:'Enter adds a ━━━━ divider | a boundary labels the block above it: ### = InfoMercial, === = Topic; whatever is left is Content | Shift+Enter: line break only | Ctrl+Enter: push to wall',
  find:'Filter cards / ### / ===',tabSheet:'EzSheet',tabPics:'Images & 3D',tabMedia:'Media',cancel:'Cancel',push:'✓ Push to wall'
};

/* Markup. o.ids: keep the wall's element ids (input, taBar, tabSheet, …) that the rest of the
   wall script uses; o.i18n: add the wall's data-i18n keys; o.labels: text overrides;
   o.btnClass / o.pushClass: button classes; o.picsHTML: content of the Images & 3D pane;
   o.mediaHTML: content of the tabMedia pane;
   o.top: extra HTML right after the hint line. */
function html(o){
  o=o||{};var L={},k;for(k in DEF)L[k]=DEF[k];if(o.labels)for(k in o.labels)if(o.labels[k]!=null)L[k]=o.labels[k];
  var id=function(n){ return o.ids?' id="'+n+'"':''; };
  var i18=function(n){ return o.i18n?' data-i18n="'+n+'"':''; };
  var b=o.btnClass||'tool-btn ezs-btn',pb=o.pushClass||'tool-btn primary ezs-btn';
  return ''
  +'<div class="toolbar ezs-hint"><span style="font-size:10px;opacity:.6"'+i18('hint')+'>'+esc(L.hint)+'</span></div>'
  +(o.top||'')
  +'<div class="tabs" role="tablist">'
  +'<button type="button" class="tab" data-ezs="tabSheet"'+id('tabSheet')+' role="tab" aria-selected="true"'+i18('tabSheet')+'>'+esc(L.tabSheet)+'</button>'
  +'<button type="button" class="tab" data-ezs="tabPics"'+id('tabPics')+' role="tab" aria-selected="false"'+i18('tabPics')+'>'+esc(L.tabPics)+'</button>'
  +'<button type="button" class="tab" data-ezs="tabMedia"'+id('tabMedia')+' role="tab" aria-selected="false"'+i18('tabMedia')+'>'+esc(L.tabMedia)+'</button>'
  +'</div>'
  +'<div class="ta-find" data-ezs="find"'+id('taFind')+'><input data-ezs="findQ"'+id('taFindQ')+' type="search" placeholder="'+esc(L.find)+'" aria-label="Filter cards" autocomplete="off" spellcheck="false" aria-expanded="false">'
  +'<button type="button" class="fchip" data-only="a" aria-pressed="false" title="List === (Topic) separators">===</button>'
  +'<button type="button" class="fchip" data-only="c" aria-pressed="false" title="List ### (InfoMercial) separators">###</button>'
  +'<span class="fcount" data-ezs="findN"'+id('taFindN')+' aria-live="polite"></span>'
  +'<div class="ta-find-list" data-ezs="findList"'+id('taFindList')+' role="listbox" hidden></div></div>'
  +'<div class="ta-wrap pane" data-ezs="paneSheet"'+id('paneSheet')+'><textarea data-ezs="input"'+id('input')+(o.placeholder?' placeholder="'+esc(o.placeholder)+'"':'')+' spellcheck="false"></textarea>'
  +'<div class="ta-bar" data-ezs="bar"'+id('taBar')+'><div class="ta-thumb" data-ezs="thumb"'+id('taThumb')+'></div></div></div>'
  +'<div class="pane pane-pics" data-ezs="panePics"'+id('panePics')+' hidden>'+(o.picsHTML||'')+'</div>'
  +'<div class="pane pane-media" data-ezs="paneMedia"'+id('paneMedia')+' hidden>'+(o.mediaHTML||'')+'</div>'
  +'<div class="ezs-foot" style="display:flex;justify-content:space-between;align-items:center;margin-top:10px"><span data-ezs="count"'+id('count')+'></span>'
  +'<div style="display:flex;gap:8px"><button type="button" class="'+b+'" data-ezs="cancel"'+i18('cancel')+'>'+esc(L.cancel)+'</button>'
  +'<button type="button" class="'+pb+'" data-ezs="push"'+id('btnPush')+i18('push')+'>'+esc(L.push)+'</button></div></div>';
}

/* Editor styles shared by both hosts. Rules under .ezs only apply on hosts that have no wall
   stylesheet of their own (the homepage); the wall panel keeps its look. */
var CSS=''
+'.ta-wrap textarea{flex:1;min-height:0;scrollbar-width:none}'
+'.ta-wrap textarea::-webkit-scrollbar{width:0;height:0}'
+'.ta-bar{width:30px;flex:0 0 30px;border:2px solid var(--ink);border-radius:999px;background:var(--cloud);position:relative;touch-action:none;cursor:pointer}'
+'.ta-bar[hidden]{display:none}'
+'.ta-thumb{position:absolute;left:3px;right:3px;top:0;min-height:56px;border-radius:999px;background:var(--teal);box-shadow:inset 0 0 0 1.5px var(--ink);cursor:grab}'
+'.ta-bar.drag .ta-thumb{cursor:grabbing;background:#0b6b6a}'
+'.ta-find[hidden]{display:none}'
+'.ta-find{position:relative;display:flex;gap:6px;align-items:center;margin:0 0 6px}'
+'.ta-find input{flex:1;min-width:0;border:2px solid var(--ink);border-radius:999px;padding:6px 12px;font:inherit;font-size:12px;background:#fff}'
+'.ta-find .fchip{border:2px solid var(--ink);border-radius:999px;padding:4px 9px;font-size:11px;font-weight:900;background:#fff;cursor:pointer;font-family:monospace}'
+'.ta-find .fchip[aria-pressed="true"]{background:var(--teal);color:#fff}'
+'.ta-find .fcount{font-size:11px;font-weight:800;opacity:.7;min-width:56px;text-align:right}'
+'.ta-find-list{position:absolute;left:0;right:0;top:100%;margin-top:4px;z-index:6;max-height:260px;overflow:auto;background:#fff;border:2px solid var(--ink);border-radius:12px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:4px}'
+'.ta-find-list[hidden]{display:none}'
+'.ta-find-list .fm{display:flex;gap:8px;align-items:baseline;padding:4px 8px;border-radius:8px;cursor:pointer;font-size:12px;white-space:nowrap;overflow:hidden}'
+'.ta-find-list .fm.on,.ta-find-list .fm:hover{background:var(--cloud)}'
+'.ta-find-list .fl{font-family:monospace;opacity:.6;min-width:42px;text-align:right}'
+'.ta-find-list .fz{font-size:10px;font-weight:900;border:1.5px solid var(--ink);border-radius:999px;padding:0 6px}'
+'.ta-find-list .fz.za{background:#fde68a}.ta-find-list .fz.zc{background:#bfdbfe}'
+'.ta-find-list .fp{overflow:hidden;text-overflow:ellipsis}'
+'.ta-find-list .fm.sep .fp{font-weight:800}'
/* homepage host */
+'.ezs{--ink:#111;--teal:#0f8b8d;--cloud:#f3f1ec;--yellow:#ffe27a;color:var(--ink)}'
+'.ezs .toolbar{display:flex;flex-wrap:wrap;gap:8px;margin:4px 0 8px;align-items:center}'
+'.ezs .tabs{display:flex;gap:6px;margin:6px 0 8px}'
+'.ezs .tab{border:2px solid var(--ink);border-radius:999px;padding:6px 14px;font:inherit;font-size:11px;font-weight:900;background:#fff;cursor:pointer;color:var(--ink)}'
+'.ezs .tab[aria-selected="true"]{background:var(--ink);color:#fff}'
+'.ezs .pane[hidden]{display:none}'
+'.ezs .ta-wrap{display:flex;gap:8px;height:var(--ezs-h,380px);position:relative}'
+'.ezs .ta-wrap textarea{width:100%;border:2px solid var(--ink);border-radius:12px;padding:12px;font-family:monospace;background:var(--cloud);line-height:1.8;font-size:12px;resize:none;color:var(--ink)}'
+'.ezs .pane-pics{display:flex;flex-direction:column;gap:10px;min-height:160px}'
+'.ezs .ezs-btn{border:2px solid var(--ink);border-radius:999px;padding:8px 14px;font-size:11px;font-weight:800;cursor:pointer;background:#fff;color:var(--ink)}'
+'.ezs .ezs-btn.primary{background:var(--teal);color:#fff}'
+'.ezs .ezs-btn:disabled{opacity:.6;cursor:not-allowed}'
+'.ezs [data-ezs="count"]{font-size:12px;font-weight:800;opacity:.75}';

/* Read-only block index for the filter: taken from wall-core's own parser (single source).
   src = the text of wall-core.js; only the pure section between the markers is evaluated. */
function pureFromCore(src){
  src=String(src||'');
  var a=src.indexOf('function ezParseSheet('),b=src.indexOf('/* Server sheet (tagged)');
  if(a<0||b<a) return null;
  return (new Function(src.slice(a,b)+';return {ezParseSheet:ezParseSheet,ezSheetIndex:ezSheetIndex,ezSheetFilter:ezSheetFilter};'))();
}

/* Wire an editor rendered by html() inside root. Options (all optional):
   index(text) / filter(index,q,only): block index + matcher (WallCore.ezSheetIndex/ezSheetFilter);
   onPush(), onCancel(), onTab(pics) (default: toggles the panes), onInput(text), count(text) -> string,
   enterDivider (default true): Enter at the end of a finished block inserts a ━━━━ divider. */
function mount(root,o){
  o=o||{};
  var q=function(n){ return root.querySelector('[data-ezs="'+n+'"]'); };
  var ta=q('input'),bar=q('bar'),thumb=q('thumb'),box=q('find'),fq=q('findQ'),list=q('findList'),fcnt=q('findN'),cnt=q('count');
  var tabS=q('tabSheet'),tabP=q('tabPics'),tabM=q('tabMedia'),paneS=q('paneSheet'),paneP=q('panePics'),paneM=q('paneMedia'),bCancel=q('cancel'),bPush=q('push');
  var offs=[];var on=function(el,t,f,opt){ if(!el||!el.addEventListener) return; el.addEventListener(t,f,opt); offs.push(function(){ el.removeEventListener(t,f,opt); }); };
  if(!ta) return null;
  var api={ta:ta,root:root};

  /* ---- custom scroll bar ---- */
  function sync(){
    if(!bar||!thumb) return;
    var vh=ta.clientHeight,sh=ta.scrollHeight;
    if(sh<=vh+2){ bar.hidden=true; return; }
    bar.hidden=false;
    var trackH=bar.clientHeight,th=Math.max(56,Math.round(trackH*vh/sh));
    var max=sh-vh,pos=max?Math.round((trackH-th)*(ta.scrollTop/max)):0;
    thumb.style.height=th+'px';thumb.style.top=pos+'px';
  }
  api.sync=sync;
  if(bar&&thumb){
    var drag=null;
    var geo=function(){ var vh=ta.clientHeight,sh=ta.scrollHeight,th=thumb.offsetHeight||56;
      return {max:Math.max(0,sh-vh),room:Math.max(1,bar.clientHeight-th),vh:vh}; };
    on(bar,'pointerdown',function(e){
      if(e.button!==0) return;
      e.preventDefault();
      var g=geo();
      if(e.target===thumb){
        drag={id:e.pointerId,y:e.clientY,st:ta.scrollTop,k:g.max/g.room};
        try{ bar.setPointerCapture(e.pointerId); }catch(_){}
        bar.classList.add('drag');
      }else{
        var r=thumb.getBoundingClientRect();
        ta.scrollTop+=(e.clientY<r.top?-1:1)*Math.max(20,g.vh-24);
      }
    });
    on(bar,'pointermove',function(e){
      if(!drag||e.pointerId!==drag.id) return;
      e.preventDefault();
      ta.scrollTop=drag.st+(e.clientY-drag.y)*drag.k;
    });
    var end=function(e){ if(!drag||e.pointerId!==drag.id) return; drag=null; bar.classList.remove('drag');
      try{ bar.releasePointerCapture(e.pointerId); }catch(_){} };
    on(bar,'pointerup',end);on(bar,'pointercancel',end);on(bar,'lostpointercapture',end);
    on(bar,'wheel',function(e){
      e.preventDefault();
      ta.scrollTop+=e.deltaY*(e.deltaMode===1?16:e.deltaMode===2?ta.clientHeight:1);
    },{passive:false});
    if(typeof ResizeObserver!=='undefined'){ var ro=new ResizeObserver(function(){ (G.requestAnimationFrame||setTimeout)(sync); }); ro.observe(ta); ro.observe(bar); offs.push(function(){ ro.disconnect(); }); }
    else on(G,'resize',sync);
  }
  on(ta,'scroll',sync);

  /* ---- count ---- */
  function count(){ if(cnt&&o.count){ try{ cnt.textContent=o.count(ta.value)||''; }catch(_){} } }
  api.count=count;
  on(ta,'input',function(){ sync(); count(); if(o.onInput) o.onInput(ta.value); });

  /* ---- keys: Esc = cancel, Ctrl/Cmd+Enter = push, Enter = divider after a finished block ---- */
  on(ta,'keydown',function(e){
    if(e.key==='Escape'){ if(o.onCancel&&o.escCancel!==false){ e.stopPropagation(); o.onCancel(); } }
    else if(e.key==='Enter'&&(e.ctrlKey||e.metaKey)){ if(o.onPush){ e.preventDefault(); o.onPush(); } }
    else if(o.enterDivider!==false&&e.key==='Enter'&&!e.shiftKey&&!e.ctrlKey&&!e.metaKey&&!e.altKey&&!e.isComposing){
      var v=ta.value,pos=ta.selectionStart;if(pos!==ta.selectionEnd) return;
      var ls=v.lastIndexOf('\n',pos-1)+1;var le=v.indexOf('\n',pos);if(le<0) le=v.length;
      var cur=v.slice(ls,le);
      if(!cur.trim()||isSep(cur)||isZone(cur)||isCZone(cur)) return;
      var lines=v.slice(0,le).split('\n'),seg=[];
      for(var k=lines.length-1;k>=0&&!isSep(lines[k])&&!isZone(lines[k])&&!isCZone(lines[k]);k--) seg.unshift(lines[k]);
      var s=seg.join('\n').trim();
      if(s.charAt(0)==='<'&&!closed(s)) return;
      e.preventDefault();
      ta.setRangeText('\n'+SEP+'\n',le,le,'end');
      ta.dispatchEvent(new Event('input',{bubbles:true}));
    }
  });

  /* ---- tabs ---- */
  function setTab(which){
    var isMedia = which === 'media' || which === 'tabMedia' || which === 2;
    var isPics = which === 'pics' || which === 'tabPics' || which === 1 || which === true;
    var isSheet = !isMedia && !isPics;
    if(paneS) paneS.hidden=Boolean(!isSheet);
    if(paneP) paneP.hidden=Boolean(!isPics);
    if(paneM) paneM.hidden=Boolean(!isMedia);
    if(box) box.hidden=Boolean(!isSheet);
    if(tabS) tabS.setAttribute('aria-selected',String(isSheet));
    if(tabP) tabP.setAttribute('aria-selected',String(isPics));
    if(tabM) tabM.setAttribute('aria-selected',String(isMedia));
    if(isSheet) (G.requestAnimationFrame||setTimeout)(sync);
  }
  api.setTab=setTab;
  on(tabS,'click',function(){ if(o.onTab) o.onTab('sheet'); else setTab('sheet'); });
  on(tabP,'click',function(){ if(o.onTab) o.onTab('pics'); else setTab('pics'); });
  on(tabM,'click',function(){ if(o.onTab) o.onTab('media'); else setTab('media'); });
  on(bCancel,'click',function(){ if(o.onCancel) o.onCancel(); });
  on(bPush,'click',function(){ if(o.onPush) o.onPush(); });

  /* ---- filter (read-only; never edits the text) ---- */
  var index=o.index||(G.WallCore&&G.WallCore.ezSheetIndex),filter=o.filter||(G.WallCore&&G.WallCore.ezSheetFilter);
  api.setParser=function(ix,fl){ index=ix;filter=fl;idxVal=null;if(box) box.hidden=!index||(paneS&&paneS.hidden); };
  var only='',hits=[],sel=-1,deb=0,idx=null,idxVal=null;
  if(box&&!index) box.hidden=true;
  function getIndex(){ if(idxVal!==ta.value){ idxVal=ta.value; idx=index(idxVal); } return idx; }
  function close(){ if(list) list.hidden=true; if(fq) fq.setAttribute('aria-expanded','false'); }
  function paint(){
    if(!index||!fq||!list) return;
    var act=!!(fq.value.trim()||only);
    if(!act){ hits=[]; fcnt.textContent=''; close(); return; }
    hits=filter(getIndex(),fq.value,only);
    fcnt.textContent=hits.length+' match'+(hits.length===1?'':'es');
    sel=hits.length?0:-1;
    var shown=hits.slice(0,300);
    list.innerHTML=shown.map(function(h,i){
      return '<div class="fm'+(h.kind==='sep'?' sep':'')+(i===sel?' on':'')+'" role="option" data-i="'+i+'">'
        +'<span class="fl">'+(h.line+1)+'</span><span class="fz z'+h.zone+'">'+esc(h.zname)+'</span>'
        +'<span class="fp">'+esc(h.preview||h.raw)+(h.cid?' <small style="opacity:.55">'+esc(h.cid)+'</small>':'')+'</span></div>';
    }).join('')+(hits.length>300?'<div class="fm" style="opacity:.6">… '+(hits.length-300)+' more, refine the filter</div>':'');
    list.hidden=!hits.length; fq.setAttribute('aria-expanded',String(!list.hidden));
  }
  function mark(){ Array.prototype.forEach.call(list.children,function(el,i){ el.classList.toggle('on',i===sel); if(i===sel&&el.scrollIntoView) el.scrollIntoView({block:'nearest'}); }); }
  /* pixel offset of a character position, measured with a mirror so wrapped lines count */
  function topOf(pos){
    var cs=getComputedStyle(ta),m=document.createElement('div');
    ['fontFamily','fontSize','fontWeight','lineHeight','letterSpacing','paddingLeft','paddingRight','paddingTop','borderLeftWidth','borderRightWidth','boxSizing','tabSize','wordBreak','overflowWrap'].forEach(function(k){ m.style[k]=cs[k]; });
    m.style.position='absolute';m.style.visibility='hidden';m.style.top='0';m.style.left='-9999px';m.style.whiteSpace='pre-wrap';m.style.width=ta.clientWidth+'px';m.style.borderStyle='solid';m.style.borderTopWidth='0';
    m.textContent=ta.value.slice(0,pos);document.body.appendChild(m);var h=m.scrollHeight;m.remove();
    return h-(parseFloat(cs.lineHeight)||20);
  }
  function go(h){
    if(!h) return;
    var lines=ta.value.split('\n'),a=0,k;
    for(k=0;k<h.line&&k<lines.length;k++) a+=lines[k].length+1;
    var b=a;for(k=h.line;k<=h.end&&k<lines.length;k++) b+=lines[k].length+(k<h.end?1:0);
    ta.focus({preventScroll:true});ta.setSelectionRange(a,b);
    ta.scrollTop=Math.max(0,Math.round(topOf(a)-12));
    sync(); close();
  }
  api.go=go;
  function setOnly(v){ only=v; Array.prototype.forEach.call(box.querySelectorAll('.fchip'),function(c){ c.setAttribute('aria-pressed',String(c.dataset.only===only)); }); }
  if(box&&fq&&list){
    on(fq,'input',function(){ clearTimeout(deb); deb=setTimeout(paint,120); });
    on(fq,'focus',function(){ if(fq.value.trim()||only) paint(); });
    on(fq,'keydown',function(e){
      if(e.key==='Escape'){ e.preventDefault(); e.stopPropagation(); fq.value=''; setOnly(''); paint(); return; }
      if(e.key==='ArrowDown'||e.key==='ArrowUp'){
        if(list.hidden) paint();
        if(!hits.length) return; e.preventDefault();
        var n=Math.min(hits.length,300); sel=(sel+(e.key==='ArrowDown'?1:-1)+n)%n; mark(); return;
      }
      if(e.key==='Enter'){ e.preventDefault(); clearTimeout(deb); if(list.hidden||!hits.length) paint(); go(hits[sel<0?0:sel]); }
    });
    on(list,'mousedown',function(e){ e.preventDefault(); });
    on(list,'click',function(e){ var r=e.target.closest('[data-i]'); if(r) go(hits[+r.dataset.i]); });
    on(box,'click',function(e){ var c=e.target.closest('.fchip'); if(!c) return; setOnly(only===c.dataset.only?'':c.dataset.only); paint(); fq.focus(); });
    on(document,'pointerdown',function(e){ if(!box.contains(e.target)) close(); });
    on(ta,'input',function(){ if(!list.hidden){ clearTimeout(deb); deb=setTimeout(paint,250); } });
  }

  /* value setter for hosts that own the text (React): keeps caret/scroll when unchanged */
  api.setValue=function(v){ v=String(v==null?'':v); if(ta.value!==v){ ta.value=v; idxVal=null; sync(); count(); } };
  api.destroy=function(){ clearTimeout(deb); offs.splice(0).forEach(function(f){ f(); }); };
  sync(); count();
  return api;
}

var API={SEP:SEP,ZSEP:ZSEP,CSEP:CSEP,isSep:isSep,isZone:isZone,isCZone:isCZone,tagDepth:tagDepth,closed:closed,esc:esc,html:html,CSS:CSS,mount:mount,pureFromCore:pureFromCore,DEF:DEF};
G.EzSheetEditor=API;
})(typeof window!=='undefined'?window:globalThis);
