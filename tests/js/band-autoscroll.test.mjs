// Zone a/c bands: auto-scroll only with >4 cards, pause on hover/hidden/reduced-motion,
// and repeated redraws (pack -> bandUpdate) must not restart or stack the timer.
import test from 'node:test'; import assert from 'node:assert'; import fs from 'node:fs';
const src = fs.readFileSync(new URL('../../resources/js/components/wall-core.js', import.meta.url), 'utf8');
const code = src.slice(src.indexOf('const ZA_MS'), src.indexOf('function zoneAInit'));
function env({reduce=false}={}){
  let now=0,id=0;const timers=new Map();
  const setInterval=(f,ms)=>{timers.set(++id,{f,ms,next:now+ms});return id;};
  const clearInterval=i=>timers.delete(i);
  const tick=ms=>{const end=now+ms;for(;;){let t=null;for(const[k,v]of timers)if(v.next<=end&&(!t||v.next<t[1].next))t=[k,v];if(!t)break;now=t[1].next;t[1].next+=t[1].ms;t[1].f();}now=end;};
  const L={};const on=(o)=>(t,f)=>{(L[o+t]=L[o+t]||[]).push(f);};
  const fire=(o,t,e={})=>(L[o+t]||[]).forEach(f=>f(e));
  const mk=()=>({style:{},classList:{add(){},remove(){},toggle(){}},hidden:false,dataset:{},addEventListener(){},querySelectorAll:()=>[],get offsetWidth(){return 0;}});
  const track={appendChild(){}};
  const sec={...mk(),hidden:true,clientWidth:1200,addEventListener:on('sec'),insertAdjacentHTML(){},querySelector:()=>track,contains:x=>x===sec,getBoundingClientRect:()=>({left:0,right:1200,top:0,bottom:300})};
  const document={hidden:false,body:mk(),documentElement:{clientWidth:1200,addEventListener:on('html')},addEventListener:on('doc'),getElementById:i=>i==='zoneA'?sec:null};
  const window={matchMedia:()=>({matches:reduce,addEventListener(){}})};
  const before={insertAdjacentHTML(){}};let made=0;
  const api=new Function('setInterval','clearInterval','document','window','matchMedia','addEventListener','dispatchEvent','Event','GAP','fitH','applySize','foldSync','makeCard','dropCard','mountQ','pump','ezBandRailOn','bandRailsPaint','BEAD_GAP','BEAD_RAIL',
    code+';return {ZA,bandInit,bandUpdate};')(setInterval,clearInterval,document,window,window.matchMedia,()=>{},()=>{},function(){},12,()=>200,()=>{},()=>{},()=>{made++;return mk();},()=>{},{add(){}},()=>{},()=>false,()=>{},44,34);
  // bandInit returns early if the section exists; make the first lookup miss
  let first=true;const g=document.getElementById;document.getElementById=i=>{if(first){first=false;return null;}return g(i);};
  api.bandInit(api.ZA,before,'');
  return {...api,tick,fire,timers,document,sec};
}
const cards=n=>Array.from({length:n},(_,i)=>({_id:'c'+i,_raw:'x'+i,type:'img'}));
test('>4 cards scroll; redraws do not reset or stack the timer', () => {
  const E=env();const items=cards(9);
  E.bandUpdate(E.ZA,items);assert.equal(E.timers.size,1);
  for(let i=0;i<300;i++){E.bandUpdate(E.ZA,items);E.tick(20);} // pack every 20ms for 6s
  assert.equal(E.timers.size,1,'one timer only');
  assert.ok(E.ZA.off>=1,'band advanced despite constant redraws');
});
test('4 or fewer cards stay static', () => {
  const E=env();E.bandUpdate(E.ZA,cards(4));E.tick(20000);assert.equal(E.ZA.off,0);assert.equal(E.timers.size,0);
});
test('hover pauses, leave resumes; hidden tab pauses', () => {
  const E=env();E.bandUpdate(E.ZA,cards(9));
  E.fire('sec','pointerenter');const o=E.ZA.off;E.tick(10000);assert.equal(E.ZA.off,o);assert.equal(E.timers.size,0);
  // moving onto a card iframe: leave with no relatedTarget while still inside the box -> stays paused
  E.fire('sec','pointerleave',{relatedTarget:null,clientX:10,clientY:10,pointerType:'mouse'});E.tick(10000);assert.equal(E.ZA.off,o);
  E.fire('sec','pointerleave',{relatedTarget:{},clientX:10,clientY:900,pointerType:'mouse'});E.tick(3600);assert.equal(E.ZA.off,o+1);
  E.document.hidden=true;E.fire('doc','visibilitychange');E.tick(10000);assert.equal(E.ZA.off,o+1);
  E.document.hidden=false;E.fire('doc','visibilitychange');E.tick(3600);assert.equal(E.ZA.off,o+2);
});
test('touch: finger down pauses, lift resumes', () => {
  const E=env();E.bandUpdate(E.ZA,cards(9));
  E.fire('sec','pointerdown');E.tick(8000);assert.equal(E.ZA.off,0);
  E.fire('sec','pointerleave',{relatedTarget:null,clientX:10,clientY:10,pointerType:'touch'});E.tick(3600);assert.equal(E.ZA.off,1);
});
test('prefers-reduced-motion: no auto-scroll', () => {
  const E=env({reduce:true});E.bandUpdate(E.ZA,cards(62));E.tick(20000);assert.equal(E.ZA.off,0);
});
