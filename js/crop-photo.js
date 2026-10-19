/* Local, accessible cropper. No photograph leaves the device until Save. */
(() => {
  const ns = 'http://www.w3.org/2000/svg';
  const element = (name, text, attributes = {}) => { const node = document.createElement(name); if (text) node.textContent = text; Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value)); return node; };
  let active = null;
  window.MusicMadCropPhoto = async (file, { width = 512, height = 512 } = {}) => {
    if (active) throw new Error('Termina el recorte anterior.');
    if (!file?.type.startsWith('image/')) throw new Error('Selecciona una imagen.');
    if (file.size > 20 * 1048576) throw new Error('La foto original supera 20 MB. Guarda una copia más ligera.');
    const source = URL.createObjectURL(file), original = new Image(); original.src = source;
    try { await original.decode(); } catch (_) { URL.revokeObjectURL(source); throw new Error('No se pudo abrir la foto. Si es HEIC, guarda una copia JPG.'); }
    const dialog = element('dialog', '', { class: 'crop-dialog', 'aria-labelledby': 'crop-heading' });
    const title = element('h2', width === height ? 'Tu foto, a tu manera' : 'Encuadra tu portada', { id: 'crop-heading' });
    const hint = element('p', 'Arrastra para mover. Pellizca o usa el zoom para acercar.');
    const stage = document.createElementNS(ns, 'svg');
    const sw = 300, sh = sw * height / width;
    stage.setAttribute('viewBox', `0 0 ${sw} ${sh}`); stage.setAttribute('class', 'crop-stage');
    stage.setAttribute('role', 'img'); stage.setAttribute('aria-label', 'Vista previa del recorte');
    const image = document.createElementNS(ns, 'image'); image.setAttribute('href', source); stage.append(image);
    const zoomLabel = element('label', 'Zoom', { for: 'crop-zoom' });
    const zoom = element('input', '', { type: 'range', id: 'crop-zoom', min: '100', max: '300', value: '100', step: '1' });
    const controls = element('div', '', { class: 'crop-actions' });
    const cancel = element('button', 'Cancelar', { type: 'button', class: 'btn btn-ghost' });
    const reset = element('button', 'Centrar', { type: 'button', class: 'btn btn-ghost' });
    const apply = element('button', 'Usar foto', { type: 'button', class: 'btn btn-primary' });
    controls.append(cancel, reset, apply); dialog.append(title, hint, stage, zoomLabel, zoom, controls); document.body.append(dialog);
    const previousFocus = document.activeElement;
    const base = Math.max(sw / original.naturalWidth, sh / original.naturalHeight);
    let scale = base, x = (sw - original.naturalWidth * scale) / 2, y = (sh - original.naturalHeight * scale) / 2;
    const points = new Map();
    const render = () => {
      x = Math.min(0, Math.max(sw - original.naturalWidth * scale, x));
      y = Math.min(0, Math.max(sh - original.naturalHeight * scale, y));
      image.setAttribute('x', String(x)); image.setAttribute('y', String(y));
      image.setAttribute('width', String(original.naturalWidth * scale)); image.setAttribute('height', String(original.naturalHeight * scale));
      zoom.value = String(Math.round(scale / base * 100));
    };
    const zoomTo = (next, cx = sw / 2, cy = sh / 2) => {
      const ratio = Math.max(base, Math.min(base * 3, next)) / scale;
      x = cx - (cx - x) * ratio; y = cy - (cy - y) * ratio; scale *= ratio; render();
    };
    const position = event => { const rect = stage.getBoundingClientRect(); return { x: (event.clientX - rect.left) * sw / rect.width, y: (event.clientY - rect.top) * sh / rect.height }; };
    const geometry = () => { const [a, b] = [...points.values()]; return b ? { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2, distance: Math.hypot(a.x - b.x, a.y - b.y) } : { ...a, distance: 0 }; };
    stage.addEventListener('pointerdown', event => { points.set(event.pointerId, position(event)); stage.setPointerCapture(event.pointerId); });
    stage.addEventListener('pointermove', event => {
      if (!points.has(event.pointerId)) return;
      const before = geometry(); points.set(event.pointerId, position(event)); const after = geometry();
      x += after.x - before.x; y += after.y - before.y;
      if (before.distance > 0 && after.distance > 0) zoomTo(scale * after.distance / before.distance, after.x, after.y);
      else render();
    });
    for (const event of ['pointerup', 'pointercancel', 'lostpointercapture']) stage.addEventListener(event, e => points.delete(e.pointerId));
    zoom.addEventListener('input', () => zoomTo(base * Number(zoom.value) / 100));
    reset.addEventListener('click', () => { scale = base; x = (sw - original.naturalWidth * scale) / 2; y = (sh - original.naturalHeight * scale) / 2; render(); });
    render(); dialog.showModal(); active = dialog;
    return await new Promise((resolve, reject) => {
      let settled = false;
      const finish = (result, error) => {
        if (settled) return; settled = true; active = null; dialog.close(); dialog.remove(); URL.revokeObjectURL(source);
        if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true });
        error ? reject(error) : resolve(result);
      };
      cancel.addEventListener('click', () => finish(null));
      dialog.addEventListener('cancel', event => { event.preventDefault(); finish(null); });
      dialog.addEventListener('close', () => finish(null));
      apply.addEventListener('click', async () => {
        apply.disabled = true;
        try {
          const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height;
          const context = canvas.getContext('2d'); if (!context) throw new Error('Tu navegador no pudo preparar el recorte.');
          const ratio = width / sw;
          context.fillStyle = '#ffffff'; context.fillRect(0, 0, width, height);
          context.drawImage(original, x * ratio, y * ratio, original.naturalWidth * scale * ratio, original.naturalHeight * scale * ratio);
          const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', .88));
          if (!blob) throw new Error('No se pudo guardar el recorte.');
          finish(new File([blob], 'musicmad-foto.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
        } catch (error) { finish(null, error); }
      });
    });
  };
})();
