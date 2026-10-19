document.addEventListener('click', (event) => {
  const cell = event.target.closest?.('.heat-d[data-fecha]');
  if (!cell || cell.disabled) return;
  const detail = document.querySelector('[data-heat-detail]');
  if (!detail) return;
  const date = detail.querySelector('strong');
  const count = detail.querySelector('span');
  const total = Number(cell.dataset.cantidad) || 0;
  const parsed = new Date(`${cell.dataset.fecha}T12:00:00`);
  date.textContent = new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'long', year: 'numeric' }).format(parsed);
  count.textContent = `${total} ${total === 1 ? 'contribución' : 'contribuciones'}`;
  document.querySelectorAll('.heat-d[data-fecha]').forEach((other) => {other.removeAttribute('aria-pressed');other.tabIndex=-1;});
  cell.setAttribute('aria-pressed', 'true');cell.tabIndex=0;
});


document.addEventListener('change',event=>{
  if(!event.target.matches('[data-heat-date]'))return;
  const cell=[...document.querySelectorAll('.heat-d[data-fecha]')].find(cell=>cell.dataset.fecha===event.target.value);
  cell?.click();
});


document.addEventListener('keydown',event=>{
 const cell=event.target.closest?.('.heat-d[data-fecha]');if(!cell || cell.disabled || !['ArrowUp','ArrowDown','ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
 const cells=[...cell.parentElement.querySelectorAll('.heat-d[data-fecha]')],index=cells.indexOf(cell);
 let next=index+({ArrowUp:-1,ArrowDown:1,ArrowLeft:-7,ArrowRight:7}[event.key] || 0);
 if(event.key==='Home')next=index-index%7;
 if(event.key==='End')next=Math.min(index-index%7+6,cells.findLastIndex(item=>!item.disabled));
 const target=cells[next];event.preventDefault();if(target && !target.disabled){target.click();target.focus({preventScroll:true});target.scrollIntoView({block:'nearest',inline:'nearest'});}
});
