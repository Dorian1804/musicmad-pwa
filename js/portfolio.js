(() => {
 document.addEventListener('play',event=>{
  if(!(event.target instanceof HTMLAudioElement)||!event.target.closest('.portfolio-page'))return;
  document.querySelectorAll('.portfolio-page audio').forEach(audio=>{if(audio!==event.target)audio.pause();});
 },true);
 const update=()=>{const links=[...document.querySelectorAll('.portfolio-stage nav a')];if(!links.length)return;const target=location.hash||'#sonido';links.forEach(link=>{if(link.hash===target)link.setAttribute('aria-current','location');else link.removeAttribute('aria-current');});};
 document.addEventListener('musicmad:page',update);addEventListener('hashchange',update);if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',update,{once:true});else update();
})();
