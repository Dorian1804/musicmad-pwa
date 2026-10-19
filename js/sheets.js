/* Move existing forms into a native modal; closing keeps the same DOM/draft. */
(() => {
  let current=null;
  const close=()=>{if(current){current.dialog.close();current.finish();}};
  function open(details) {
    if(!details?.matches('details[data-sheet]') || current?.details===details)return;
    close();
    const previous=document.activeElement,summary=details.querySelector(':scope > summary');
    if(!summary)return;
    const dialog=document.createElement('dialog');dialog.className='app-sheet';dialog.setAttribute('aria-labelledby','sheet-title');
    const heading=document.createElement('header');heading.className='sheet-heading';
    const title=document.createElement('h2');title.id='sheet-title';title.textContent=details.dataset.sheetTitle || summary.querySelector('strong')?.textContent || summary.textContent.trim();
    const button=document.createElement('button');button.type='button';button.textContent='×';button.setAttribute('aria-label',window.MusicMadText?window.MusicMadText('Cerrar {title}',{title:title.textContent}):'Cerrar '+title.textContent);button.addEventListener('click',()=>dialog.close());
    heading.append(title,button);
    const body=document.createElement('div');body.className='sheet-body';
    const nodes=[...details.childNodes].filter(node=>node!==summary);nodes.forEach(node=>body.append(node));
    details.open=false;dialog.append(heading,body);document.body.append(dialog);document.body.classList.add('sheet-open');
    let finished=false;
    const finish=()=>{
      if(finished)return;finished=true;
      const running=body.querySelector('form[data-upload-form][aria-busy=true]');
      nodes.forEach(node=>details.append(node));dialog.remove();
      if(current?.dialog===dialog){current=null;document.body.classList.remove('sheet-open');}
      if(!current && previous?.isConnected)previous.focus({preventScroll:true});
      if(running)document.dispatchEvent(new CustomEvent('musicmad:toast',{detail:{message:'La subida sigue. Abre Compartir para consultar su avance.'}}));
    };
    current={dialog,details,finish};
    dialog.addEventListener('close',finish,{once:true});
    dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
    let start=null;
    heading.addEventListener('pointerdown',event=>start={y:event.clientY});
    heading.addEventListener('pointerup',event=>{if(start && event.clientY-start.y>60)dialog.close();start=null;});
    dialog.showModal();button.focus();
  }
  document.addEventListener('click',event=>{
    const link=event.target.closest('a[href]');
    if(link&&!event.defaultPrevented&&event.button===0&&!event.metaKey&&!event.ctrlKey&&!event.shiftKey&&!event.altKey&&!link.target&&!link.hasAttribute('download')){
      const target=new URL(link.href,location.href);
      if(target.origin===location.origin&&target.pathname===location.pathname&&target.search===location.search&&target.hash){
        let details;try{details=document.getElementById(decodeURIComponent(target.hash.slice(1)));}catch(_){}
        if(details?.matches('details[data-sheet]')){event.preventDefault();open(details);return;}
      }
    }
    const summary=event.target.closest('details[data-sheet] > summary');
    if(!summary)return;event.preventDefault();open(summary.parentElement);
  });
  const hashSheet=()=>{
    if(!location.hash)return;
    let id;try{id=decodeURIComponent(location.hash.slice(1));}catch(_){return;}
    const details=document.getElementById(id);if(details?.matches('details[data-sheet]'))open(details);
  };
  document.addEventListener('toggle',event=>{if(event.target.matches?.('details[data-sheet]') && event.target.open)open(event.target);},true);
  document.addEventListener('musicmad:beforepage',close);
  document.addEventListener('musicmad:page',hashSheet);
  addEventListener('hashchange',hashSheet);
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',hashSheet,{once:true});else hashSheet();
  window.MusicMadOpenSheet=open;
})();
