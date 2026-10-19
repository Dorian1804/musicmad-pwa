(() => {
 let generation=0,chain=Promise.resolve();
 const owner=()=>Number(document.querySelector('meta[name="musicmad-user"]')?.content||0);
 const count=value=>{const number=Number(value);return Number.isFinite(number)?Math.max(0,Math.min(99999,Math.trunc(number))):0;};
 const update=value=>{
  const current=++generation,total=count(value);
  chain=chain.catch(()=>{}).then(async()=>{
   if(current!==generation)return;
   try{if(total&&typeof navigator.setAppBadge==='function')await navigator.setAppBadge(total);else if(typeof navigator.clearAppBadge==='function')await navigator.clearAppBadge();else if(typeof navigator.setAppBadge==='function')await navigator.setAppBadge(0);}catch(_){}
  });
 };
 const fromPage=()=>{const nav=document.querySelector('.navbar[data-unread-messages]');update(owner()&&nav?count(nav.dataset.unreadMessages)+count(nav.dataset.unreadNotifications):0);};
 document.addEventListener('musicmad:counts',event=>{if(Number(event.detail?.owner)!==owner()||!owner())return;update(count(event.detail.mensajes)+count(event.detail.avisos));});
 document.addEventListener('musicmad:session',()=>update(0));
 document.addEventListener('musicmad:page',fromPage);
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',fromPage,{once:true});else fromPage();
})();
