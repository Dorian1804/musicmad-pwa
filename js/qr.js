/* QR rendering stays local. TOTP secrets never become links, downloads or shares. */
(() => {
  function start() {
    document.querySelectorAll('[data-qr-value]').forEach(node => {
      if (node.dataset.qrReady === '1') return;
      node.dataset.qrReady = '1';
      const value = node.dataset.qrValue || '', sensitive = node.dataset.qrKind === 'totp';
      try {
        if (!value || value.length > 1800 || !window.qrcodegen?.QrCode) throw Error('qr');
        if (sensitive) {
          if (!/^otpauth:\/\/totp\/MusicMad[^?]*\?/.test(value)) throw Error('qr');
        } else {
          const parsed = new URL(value);
          if (parsed.protocol !== 'https:' || parsed.username || parsed.REPLACE_WITH_LOCAL_VALUE) throw Error('qr');
        }
        const qr = window.qrcodegen.QrCode.encodeText(value, window.qrcodegen.QrCode.Ecc.MEDIUM);
        const border = 4, scale = 5, size = (qr.size + border * 2) * scale;
        const canvas = document.createElement('canvas'); canvas.width = size; canvas.height = size;
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', sensitive ? 'Código QR para tu app autenticadora' : 'Código QR del enlace público');
        const context = canvas.getContext('2d'); if (!context) throw Error('qr');
        context.fillStyle = '#ffffff'; context.fillRect(0, 0, size, size); context.fillStyle = '#190d22';
        for (let y = 0; y < qr.size; y++) for (let x = 0; x < qr.size; x++) if (qr.getModule(x, y)) context.fillRect((x + border) * scale, (y + border) * scale, scale, scale);
        node.replaceChildren(canvas);
        if (sensitive) return;
        const actions = document.createElement('div'); actions.className = 'qr-actions';
        const status = document.createElement('p'); status.className = 'qr-status'; status.setAttribute('role', 'status');
        const download = document.createElement('a'); download.href = canvas.toDataURL('image/png');
        download.download = 'MusicMad-QR.png'; download.className = 'btn btn-ghost'; download.textContent = 'Descargar QR';
        const copy = document.createElement('button'); copy.type = 'button'; copy.className = 'btn btn-ghost'; copy.textContent = 'Copiar enlace';
        copy.addEventListener('click', async () => {
          try {
            if (!navigator.clipboard?.writeText) throw Error('clipboard');
            await navigator.clipboard.writeText(value); status.textContent = 'Enlace copiado.';
          } catch (_) {
            const input = node.parentElement?.querySelector('input[readonly]'); input?.focus(); input?.select();
            status.textContent = 'Mantén pulsado el enlace y elige Copiar.';
          }
        });
        const share = document.createElement('button'); share.type = 'button'; share.className = 'btn btn-ghost'; share.textContent = 'Compartir';
        share.addEventListener('click', async () => {
          if (!navigator.share) { status.textContent = 'Copia el enlace para compartirlo desde otra aplicación.'; return; }
          try { await navigator.share({title:'MusicMad', url:value}); status.textContent = ''; }
          catch (error) { if (error.name !== 'AbortError') status.textContent = 'No se pudo compartir. Puedes copiar el enlace.'; }
        });
        actions.append(download, copy, share); node.append(actions, status);
      } catch (_) {
        node.textContent = sensitive ? 'Usa la clave escrita para configurar tu app.' : 'No se pudo crear el QR. Revisa que el enlace público sea HTTPS.';
      }
    });
  }
  document.addEventListener('musicmad:page', start);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once:true}); else start();
})();
