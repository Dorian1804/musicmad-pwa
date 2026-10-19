(() => {
 const active=new Map();
 const remove=button=>{const item=active.get(button);if(!item)return;clearTimeout(item.timer);item.node.remove();button.classList.remove('applause-pop');active.delete(button);};
 document.addEventListener('musicmad:applause',event=>{
  const button=event.detail?.button;if(!button?.isConnected||!button.matches('button')||matchMedia('(prefers-reduced-motion: reduce)').matches)return;
  remove(button);if(active.size>=5)remove(active.keys().next().value);
  const burst=document.createElement('span');burst.className='applause-burst';burst.setAttribute('aria-hidden','true');
  for(const symbol of ['♪','♫','♪','♫','♪']){const note=document.createElement('span');note.textContent=symbol;burst.append(note);}
  button.append(burst);button.classList.add('applause-pop');active.set(button,{node:burst,timer:setTimeout(()=>remove(button),700)});
 });
 document.addEventListener('musicmad:beforepage',()=>{for(const button of [...active.keys()])remove(button);});
})();
