// EzSheet editor scroll bar (now in the shared ezsheet-editor.js, used by the wall and the homepage).
import test from 'node:test';import assert from 'node:assert';import fs from 'node:fs';import vm from 'node:vm';
const src=fs.readFileSync(new URL('../../resources/js/components/ezsheet-editor.js',import.meta.url),'utf8');
function el(extra){const h={};return Object.assign({h,hidden:false,style:{},dataset:{},classList:{add(){},remove(){},toggle(){}},
  addEventListener(t,f){h[t]=f;},removeEventListener(t){delete h[t];},setPointerCapture(){this.cap=1;},releasePointerCapture(){},setAttribute(){},querySelectorAll:()=>[],contains:()=>false},extra);}
test('EzSheet scroll bar: drag, track page, wheel',()=>{
  const G={};vm.runInNewContext(src,{window:G,globalThis:G});
  let st=0;const ta=el({clientHeight:200,scrollHeight:1000,value:''});Object.defineProperty(ta,'scrollTop',{get(){return st;},set(v){st=Math.max(0,Math.min(800,v));}});
  const bar=el({clientHeight:200});const thumb=el({offsetHeight:56,getBoundingClientRect:()=>({top:0+st*144/800,bottom:56+st*144/800})});
  const parts={input:ta,bar,thumb};
  const root={querySelector:s=>parts[(s.match(/data-ezs="([^"]+)"/)||[])[1]]||null};
  const api=G.EzSheetEditor.mount(root,{});
  assert.ok(api&&typeof api.sync==='function');
  const pe=(t,o)=>bar.h[t]({button:0,pointerId:1,preventDefault(){},...o});
  pe('pointerdown',{target:thumb,clientY:10});assert.ok(bar.cap);
  pe('pointermove',{clientY:82});assert.equal(st,400);pe('pointerup',{});
  pe('pointermove',{clientY:200});assert.equal(st,400);
  pe('pointerdown',{target:bar,clientY:190});assert.equal(st,576);
  pe('pointerdown',{target:bar,clientY:1});assert.equal(st,400);
  bar.h.wheel({deltaY:100,deltaMode:0,preventDefault(){}});assert.equal(st,500);
  api.sync();assert.equal(thumb.style.top,Math.round(144*500/800)+'px');assert.equal(thumb.style.height,'56px');
});
