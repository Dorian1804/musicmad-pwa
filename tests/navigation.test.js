const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
function fixture(fetch){
 const listeners={},timers=new Map(),toasts=[],classes=new Set(),attrs={};let sequence=0;
 const main={classList:{add:name=>classes.add(name),remove:name=>classes.delete(name)},setAttribute:(name,value)=>attrs[name]=value,removeAttribute:name=>delete attrs[name]};
 const context={window:{},document:{scripts:[],querySelector:selector=>selector==='main'?main:null,querySelectorAll:()=>[],addEventListener:(name,fn)=>listeners[name]=fn,dispatchEvent:event=>toasts.push(event.detail.message)},location:{href:'http://qa.invalid/feed.php',origin:'http://qa.invalid',pathname:'/feed.php',search:''},history:{state:{},replaceState(){}},navigator:{onLine:true},scrollX:0,scrollY:0,URL,AbortController,DOMException,fetch,setTimeout:(fn,ms)=>{timers.set(++sequence,{fn,ms});return sequence;},clearTimeout:id=>timers.delete(id),addEventListener(){},CustomEvent:class{constructor(type,data){this.type=type;this.detail=data.detail;}}};
 vm.runInNewContext(fs.readFileSync('js/navigation.js','utf8'),context);
 return {navigate:context.window.MusicMadNavigate,timers,toasts,classes,attrs};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
test('navegación lenta libera controles y explica cómo reintentar',async()=>{
 const f=fixture((url,{signal})=>new Promise((resolve,reject)=>signal.addEventListener('abort',()=>reject(new DOMException('Timeout','AbortError')))));
 const navigation=f.navigate('http://qa.invalid/bandas.php');assert.equal(f.attrs['aria-busy'],'true');[...f.timers.values()].find(timer=>timer.ms===15000).fn();assert.equal(await navigation,false);assert.equal(f.classes.has('is-loading'),false);assert.equal(f.attrs['aria-busy'],undefined);assert.match(f.toasts[0],/tardó demasiado/);
});
test('el límite también cubre una respuesta cuyo cuerpo queda incompleto',async()=>{
 const f=fixture(async(url,{signal})=>({ok:true,text:()=>new Promise((resolve,reject)=>signal.addEventListener('abort',()=>reject(new DOMException('Timeout','AbortError'))))}));
 const navigation=f.navigate('http://qa.invalid/bandas.php');await tick();const timer=[...f.timers.values()].find(timer=>timer.ms===15000);assert.ok(timer);timer.fn();assert.equal(await navigation,false);assert.equal(f.attrs['aria-busy'],undefined);
});
test('tocar otra sección cancela la anterior sin un aviso falso',async()=>{
 let calls=0;const f=fixture((url,{signal})=>++calls===1?new Promise((resolve,reject)=>signal.addEventListener('abort',()=>reject(new DOMException('Cancelled','AbortError')))):Promise.resolve({ok:false,status:503}));
 const first=f.navigate('http://qa.invalid/bandas.php'),second=f.navigate('http://qa.invalid/mercado.php');await Promise.all([first,second]);assert.equal(f.toasts.length,1);assert.match(f.toasts[0],/No pudimos abrir/);assert.equal(f.classes.has('is-loading'),false);assert.equal(f.timers.size,0);
});
