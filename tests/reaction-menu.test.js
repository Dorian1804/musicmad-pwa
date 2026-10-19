const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs');
function fixture(){
 const handlers={},timers=new Map();let next=0;
 const menus=[0,1].map(()=>({open:false,matches:s=>s==='[data-reaction-menu]',contains:node=>node===button,querySelector:()=>({focus(){focused=true;}})}));
 let focused=false;
 const button={disabled:false,isConnected:true,contains:node=>node===button,closest:selector=>selector==='.post-reaction-control'?{querySelector:()=>menus[0]}:button};
 const outside={closest:()=>null};
 const document={activeElement:button,querySelectorAll:()=>menus.filter(menu=>menu.open),addEventListener:(type,handler)=>{(handlers[type]??=[]).push(handler);}};
 const context={document,setTimeout:fn=>{timers.set(++next,fn);return next;},clearTimeout:id=>timers.delete(id)};
 vm.createContext(context);vm.runInContext(fs.readFileSync('js/reaction-menu.js','utf8'),context);
 const event=(type,extra={})=>{const e={target:button,pointerType:'touch',button:0,pointerId:1,clientX:10,clientY:10,prevented:false,stopped:false,preventDefault(){this.prevented=true;},stopImmediatePropagation(){this.stopped=true;},...extra};for(const handler of handlers[type]??[])handler(e);return e;};
 const advance=()=>{const current=[...timers.values()];timers.clear();current.forEach(fn=>fn());};
 return {menus,button,outside,event,advance,get focused(){return focused;}};
}
test('al abrir una barra se cierra la anterior',()=>{const f=fixture();f.menus.forEach(menu=>menu.open=true);f.event('toggle',{target:f.menus[0]});assert.equal(f.menus[0].open,true);assert.equal(f.menus[1].open,false);});
test('un toque corto conserva el aplauso normal',()=>{const f=fixture();f.event('pointerdown');f.event('pointerup');f.advance();assert.equal(f.menus[0].open,false);assert.equal(f.event('click').prevented,false);});
test('mantener pulsado abre las reacciones sin enviar un aplauso',()=>{const f=fixture();f.event('pointerdown');f.advance();assert.equal(f.menus[0].open,true);f.event('pointerup');const click=f.event('click');assert.equal(click.prevented,true);assert.equal(click.stopped,true);assert.equal(f.event('click').prevented,false);});
test('deslizar o cancelar el toque no abre las reacciones',()=>{for(const type of ['pointermove','pointercancel']){const f=fixture();f.event('pointerdown');f.event(type,{clientX:30});f.advance();assert.equal(f.menus[0].open,false);assert.equal(f.event('click').prevented,false);}});
test('Escape cierra la barra y devuelve el foco al botón',()=>{const f=fixture();f.menus[0].open=true;assert.equal(f.event('keydown',{key:'Escape'}).prevented,true);assert.equal(f.menus[0].open,false);assert.equal(f.focused,true);});
test('tocar fuera cierra la barra y navegar cancela un toque pendiente',()=>{const f=fixture();f.menus[0].open=true;f.event('click',{target:f.outside});assert.equal(f.menus[0].open,false);f.event('pointerdown');f.event('musicmad:beforepage');f.advance();assert.equal(f.menus[0].open,false);assert.equal(f.event('click').prevented,false);});
test('un toque de ratón o botón desactivado no activa la pulsación larga',()=>{for(const disabled of [false,true]){const f=fixture();f.button.disabled=disabled;f.event('pointerdown',disabled?{}:{pointerType:'mouse'});f.advance();assert.equal(f.menus[0].open,false);}});
