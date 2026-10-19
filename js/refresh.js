(() => {
 const pages=new Set(['feed.php','musicos.php','buscar.php','mercado.php','busco.php','bandas.php','eventos.php','notificaciones.php']);
 let start=null,movement=null,busy=false,button=null,indicator=null,timer=null;
 const allowed=()=>pages.has(location.pathname.split('/').pop()) || Boolean(window.MusicMadRoutePaths?.canRefresh(location.href));
 const blocked=()=>document.body.classList.contains('sheet-open')||document.body.classList.contains('player-screen-open')||Boolean(document.querySelector('dialog[open],form[aria-busy="true"],main[aria-busy="true"]'))||Array.from(document.querySelectorAll('main input,main textarea,main select')).some(window.MusicMadRefreshMath.dirty);
 const say=(message,type='info')=>document.dispatchEvent(new CustomEvent('musicmad:toast',{detail:{message,type}}));
 const clear=()=>{start=null;movement=null;if(indicator&&!busy)indicator.hidden=true;};
 const refresh=async()=>{
  if(busy||!allowed())return;if(blocked()){say('Termina o descarta tus cambios antes de actualizar esta lista.');return;}if(!navigator.onLine){say('Sin internet. Conservamos esta pantalla; actualízala cuando vuelva la conexión.');return;}
  if(typeof window.MusicMadNavigate!=='function'){say('La navegación todavía está cargando. Inténtalo de nuevo.');return;}
  busy=true;clearTimeout(timer);if(button)button.disabled=true;if(indicator){indicator.hidden=false;indicator.className='pull-refresh is-loading';indicator.textContent='Actualizando tu escena…';}
  const url=new URL(location.href);url.searchParams.delete('cursor');url.searchParams.delete('fragmento');url.hash='';
  try{const ok=await window.MusicMadNavigate(url.href,{push:false});if(ok===true&&indicator){indicator.className='pull-refresh is-done';indicator.textContent='Tu escena está al día';}}
  catch(_){say('No se pudo actualizar. Puedes volver a intentar.','error');}
  finally{busy=false;if(button)button.disabled=false;start=null;movement=null;timer=setTimeout(()=>{if(indicator)indicator.hidden=true;},1500);}
 };
 const bind=()=>{
  clear();button=null;document.documentElement?.classList.toggle('supports-pull-refresh',allowed());if(!allowed())return;const main=document.querySelector('main');if(!main)return;
  let toolbar=main.querySelector('.refresh-toolbar');if(!toolbar){toolbar=document.createElement('div');toolbar.className='refresh-toolbar';const hero=main.querySelector('.community-hero');if(hero)hero.append(toolbar);else main.prepend(toolbar);}
  button=document.createElement('button');button.type='button';button.className='page-refresh';button.disabled=busy;button.setAttribute('aria-label','Actualizar esta lista sin interrumpir la música');
  const icon=document.createElementNS('http://www.w3.org/2000/svg','svg');icon.setAttribute('viewBox','0 0 24 24');icon.setAttribute('aria-hidden','true');const path=document.createElementNS('http://www.w3.org/2000/svg','path');path.setAttribute('d','M20 7v5h-5M4 17v-5h5M5.5 8a7 7 0 0 1 11.8-3L20 8M4 16l2.7 3A7 7 0 0 0 18.5 16');icon.append(path);button.append(icon,document.createTextNode('Actualizar'));button.addEventListener('click',refresh);toolbar.replaceChildren(button);
 };
 const init=()=>{
  indicator=document.createElement('div');indicator.className='pull-refresh';indicator.hidden=true;indicator.setAttribute('role','status');indicator.setAttribute('aria-live','polite');document.body.append(indicator);bind();
 };
 document.addEventListener('touchstart',event=>{
  clear();if(busy||!allowed()||scrollY>1||event.touches.length!==1||blocked()||!event.target.closest('main')||event.target.closest('a,button,input,textarea,select,video,audio,[role="button"]'))return;
  start={x:event.touches[0].clientX,y:event.touches[0].clientY};
 },{passive:true});
 document.addEventListener('touchmove',event=>{
  if(!start)return;const point=event.touches[0];movement=window.MusicMadRefreshMath.move(start,point?.clientX,point?.clientY,event.touches.length);if(!movement||scrollY>1||blocked()){clear();return;}
  if(movement.intercept){if(event.cancelable)event.preventDefault();if(indicator){indicator.hidden=false;indicator.className='pull-refresh progress-'+movement.bucket+(movement.ready?' is-ready':'');indicator.textContent=movement.ready?'Suelta para actualizar':'Desliza para actualizar';}}
 },{passive:false});
 document.addEventListener('touchend',()=>{const ready=movement?.ready;clear();if(ready)refresh();},{passive:true});
 document.addEventListener('touchcancel',clear,{passive:true});document.addEventListener('musicmad:beforepage',clear);document.addEventListener('musicmad:page',bind);document.addEventListener('musicmad:session',clear);
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
