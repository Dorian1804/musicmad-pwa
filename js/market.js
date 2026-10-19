document.addEventListener('click', async (event) => {

  const thumb = event.target.closest('[data-market-thumb]');

  if (thumb) {

    const wrap = thumb.closest('.market-image-wrap');

    const main = wrap?.querySelector('[data-market-main]');

    if (main) { main.src = thumb.dataset.marketThumb; wrap.querySelectorAll('[data-market-thumb]').forEach(b => b.classList.toggle('is-active', b === thumb)); }

    return;

  }

  const share = event.target.closest('[data-market-share]');

  if (!share) return;

  const url = new URL(share.dataset.marketShare, window.location.href).href;

  try {

    if (navigator.share) await navigator.share({title: 'Instrumento en MusicMad', url});

    else if (navigator.clipboard?.writeText) { await navigator.clipboard.writeText(url); const old=share.textContent; share.textContent='✓ Enlace copiado'; setTimeout(()=>share.textContent=old,1800); }

    else window.prompt('Copia el enlace del anuncio:', url);

  } catch (error) { if (error.name !== 'AbortError') window.prompt('Copia el enlace del anuncio:', url); }

});



/* Optimize camera images locally before upload, keeping their original files on failure. */

(() => {

 const states=new WeakMap();

 const toast=message=>document.dispatchEvent(new CustomEvent('musicmad:toast',{detail:{message}}));

 function setBusy(form,busy){form.dataset.photoProcessing=busy?'1':'0';form.querySelectorAll('button[type=submit]').forEach(button=>button.disabled=busy);}

 async function optimize(file){

  if(!['image/jpeg','image/png','image/webp'].includes(file.type) || file.size>20*1024*1024)throw new Error('Usa fotos JPG, PNG o WebP de hasta 20 MB.');

  const url=URL.createObjectURL(file);let image;

  try{

   image=new Image();image.src=url;await image.decode();

   if(!image.naturalWidth || !image.naturalHeight)throw new Error('No pudimos abrir una de las fotos.');

   const ratio=Math.min(1,1600/Math.max(image.naturalWidth,image.naturalHeight)),canvas=document.createElement('canvas');

   canvas.width=Math.round(image.naturalWidth*ratio);canvas.height=Math.round(image.naturalHeight*ratio);

   const ctx=canvas.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.drawImage(image,0,0,canvas.width,canvas.height);

   const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',.86));

   if(!blob || blob.size>5*1024*1024)throw new Error('No pudimos reducir la foto. Prueba con otra imagen.');

   return new File([blob],file.name.replace(/\.[^.]+$/,'')+'.jpg',{type:'image/jpeg',lastModified:file.lastModified});

  }finally{URL.revokeObjectURL(url);}

 }

 function cleanup(input){

  const state=states.get(input);if(!state)return;

  state.urls.forEach(url=>URL.revokeObjectURL(url));state.preview.remove();states.delete(input);

 }

 document.addEventListener('change',async event=>{

  const input=event.target.closest('input[data-max-files]');if(!input)return;

  const original=[...input.files],form=input.form;cleanup(input);

  if(!original.length)return;

  if(original.length>Number(input.dataset.maxFiles)){toast('Elige hasta 4 fotos. Tus archivos no se han borrado.');input.setCustomValidity('Elige hasta 4 fotos.');return;}

  input.setCustomValidity('');setBusy(form,true);

  const generation={urls:[],preview:document.createElement('div')};generation.preview.className='photo-upload-previews';states.set(input,generation);

  const status=document.createElement('p');status.setAttribute('role','status');status.textContent='Preparando fotos…';input.parentElement.append(status);

  try{

   const optimized=await Promise.all(original.map(optimize));if(states.get(input)!==generation)return;

   const transfer=new DataTransfer();optimized.forEach(file=>transfer.items.add(file));input.files=transfer.files;

   optimized.forEach(file=>{const image=document.createElement('img'),url=URL.createObjectURL(file);generation.urls.push(url);image.src=url;image.alt='Vista previa de foto del instrumento';generation.preview.append(image);});

   input.parentElement.append(generation.preview);status.textContent='Fotos listas. Puedes revisar las imágenes antes de publicar.';

  }catch(error){input.setCustomValidity(error.message);toast(error.message);status.textContent=error.message;}

  finally{if(states.get(input)===generation)setBusy(form,false);}

 });

 document.addEventListener('submit',event=>{

  if(event.target.dataset.photoProcessing==='1'){event.preventDefault();event.stopImmediatePropagation();toast('Espera un momento: estamos preparando tus fotos.');}

 },true);

 document.addEventListener('musicmad:beforepage',()=>document.querySelectorAll('input[data-max-files]').forEach(cleanup));

})();

