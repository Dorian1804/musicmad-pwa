const test=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');const math=require('../js/refresh-math.js');
test('solo un gesto vertical hacia abajo alcanza el umbral, nunca un pellizco o movimiento inválido',()=>{
 assert.equal(math.move({x:30,y:10},32,105).ready,true);assert.equal(math.move({x:30,y:10},31,50).ready,false);assert.equal(math.move({x:30,y:10},80,110),null);assert.equal(math.move({x:30,y:10},30,2),null);assert.equal(math.move({x:30,y:10},30,105,2),null);assert.equal(math.move({x:NaN,y:10},30,105),null);assert.equal(math.move({x:30,y:10},30,200).bucket,4);
});
test('borradores, archivos y controles cambiados bloquean el refresco, selects implícitos sin editar no lo bloquean',()=>{
 assert.equal(math.dirty({type:'text',value:'idea',defaultValue:''}),true);assert.equal(math.dirty({type:'text',value:'idea',defaultValue:'idea'}),false);assert.equal(math.dirty({type:'file',files:[{}]}),true);assert.equal(math.dirty({type:'checkbox',checked:true,defaultChecked:false}),true);assert.equal(math.dirty({type:'hidden',value:'new',defaultValue:'old'}),false);
 assert.equal(math.dirty({options:[{selected:true,defaultSelected:false},{selected:false,defaultSelected:false}]}),false);assert.equal(math.dirty({options:[{selected:false,defaultSelected:false},{selected:true,defaultSelected:false}]}),true);assert.equal(math.dirty({multiple:true,options:[{selected:true,defaultSelected:false}]}),true);
});
function fixture(){
 const events={},toasts=[],calls=[],nodes=[];let dirtyFields=[],navigationResult=true;const classes=new Set();
 class Node{constructor(tag){this.tag=tag;this.children=[];this.attrs={};this.hidden=false;this.disabled=false;this.className='';this.handlers={};}setAttribute(k,v){this.attrs[k]=v;}append(...kids){this.children.push(...kids);}prepend(node){this.children.unshift(node);}replaceChildren(...kids){this.children=kids;}addEventListener(k,fn){this.handlers[k]=fn;}querySelector(selector){return selector==='.refresh-toolbar'?this.children.find(n=>n.className==='refresh-toolbar'):null;}}
 const main=new Node('main'),body=new Node('body');body.classList={contains:name=>classes.has(name)};const document={readyState:'complete',body,createElement:tag=>{const n=new Node(tag);nodes.push(n);return n;},createElementNS:(_,tag)=>new Node(tag),createTextNode:s=>s,querySelector:selector=>selector==='main'?main:null,querySelectorAll:()=>dirtyFields,addEventListener:(name,fn)=>{events[name]=fn;},dispatchEvent:event=>toasts.push(event.detail)};
 const window={MusicMadRefreshMath:math,MusicMadNavigate:async(url,opts)=>{calls.push({url,opts});return navigationResult;}};const context={window,document,location:{pathname:'/feed.php',href:'http://localhost/feed.php?genero=Rock&cursor=old#algo'},scrollY:0,navigator:{onLine:true},URL,CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail;}},setTimeout:()=>1,clearTimeout:()=>{}};
 vm.runInNewContext(fs.readFileSync(require.resolve('../js/refresh.js'),'utf8'),context);
 return {events,window,context,calls,toasts,nodes,main,button:()=>main.children[0].children[0],indicator:()=>body.children[0],fields:value=>dirtyFields=value,result:value=>navigationResult=value,classes};
}
test('botón actualiza con fetch sin agregar historial, retira cursor y solo anuncia éxito confirmado',async()=>{
 const f=fixture();await f.button().handlers.click();assert.equal(f.calls.length,1);assert.equal(f.calls[0].url,'http://localhost/feed.php?genero=Rock');assert.equal(f.calls[0].opts.push,false);assert.equal(f.indicator().textContent,'Tu escena está al día');assert.equal(f.button().disabled,false);
 f.result(false);await f.button().handlers.click();assert.notEqual(f.indicator().textContent,'Tu escena está al día');
});
test('offline o borrador conserva pantalla; gesto solo al inicio y una vez al soltar',async()=>{
 const f=fixture();f.context.navigator.onLine=false;await f.button().handlers.click();assert.equal(f.calls.length,0);f.context.navigator.onLine=true;f.fields([{type:'file',files:[{}]}]);await f.button().handlers.click();assert.equal(f.calls.length,0);f.fields([]);
 const target={closest:selector=>selector==='main'?f.main:null};f.events.touchstart({target,touches:[{clientX:30,clientY:10}]});let intercepted=false;f.events.touchmove({touches:[{clientX:31,clientY:110}],cancelable:true,preventDefault:()=>intercepted=true});assert.equal(intercepted,true);f.events.touchend();await Promise.resolve();assert.equal(f.calls.length,1);f.events.touchend();assert.equal(f.calls.length,1);
 f.context.scrollY=30;f.events.touchstart({target,touches:[{clientX:30,clientY:10}]});f.events.touchmove({touches:[{clientX:30,clientY:110}],cancelable:true,preventDefault:()=>{}});f.events.touchend();assert.equal(f.calls.length,1);
});
test('rutas de edición/chat y formularios de los nuevos dominios preservan su navegación prevista',()=>{
 const f=fixture();f.context.location.pathname='/perfil.php';f.events['musicmad:page']();assert.equal(f.main.children.length,1);const source=fs.readFileSync(require.resolve('../js/navigation.js'),'utf8');for(const name of ['reto-accion','recomendacion-accion','bienvenida-accion'])assert.ok(source.includes(name));assert.ok(source.includes('return await install'));assert.ok(!fs.readFileSync(require.resolve('../js/refresh.js'),'utf8').includes('.reload('));
});
