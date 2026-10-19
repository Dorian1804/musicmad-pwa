// ============================================================

//  MusicMad – Interacciones comunes de la interfaz

//  js/app.js

// ============================================================

// Missing historical photos must not turn the account and composer into broken-image icons.

(() => {

  const fallback = new URL('../img/avatares/musicos/m0.svg', document.currentScript.src).href;

  const repair = image => {

    if (!(image instanceof HTMLImageElement) || (image.currentSrc || image.src) === fallback) return;

    let source;

    try { source = new URL(image.currentSrc || image.src, document.baseURI); } catch (_) { return; }

    if (source.origin !== location.origin || !(/\/uploads\/avatars\//.test(source.pathname)

      || (/\/media-image\.php$/.test(source.pathname) && source.searchParams.get('kind') === 'avatars'))) return;

    image.dataset.avatarUnavailable = '1';

    image.src = fallback;

  };

  const scan = () => document.querySelectorAll('img').forEach(image => {

    if (image.complete && image.naturalWidth === 0) repair(image);

  });

  document.addEventListener('error', event => repair(event.target), true);

  document.addEventListener('DOMContentLoaded', scan);

  document.addEventListener('musicmad:page', scan);

})();



document.addEventListener('DOMContentLoaded', () => {

  // Permite ver las fotos de perfil a mayor tamaño; el botón de cámara queda para cambiarlas.

  const photoDialog = document.getElementById('photo-viewer');

  const photoDialogImage = document.getElementById('photo-viewer-image');

  const openPhoto = (image) => {

    if (!photoDialog || !photoDialogImage || !image?.src) return;

    photoDialogImage.src = image.currentSrc || image.src;

    photoDialogImage.alt = image.alt || 'Foto de perfil ampliada';

    if (!photoDialog.open) photoDialog.showModal();

  };

  document.addEventListener('click', (event) => {

    const image = event.target.closest('.profile-card-avatar,.musico-avatar');

    if (image) openPhoto(image);

  });

  document.addEventListener('keydown', (event) => {

    const image = event.target.closest?.('.profile-card-avatar,.musico-avatar');

    if (image && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); openPhoto(image); }

  });

  photoDialog?.querySelector('[data-photo-close]')?.addEventListener('click', () => photoDialog.close());

  photoDialog?.addEventListener('click', (event) => { if (event.target === photoDialog) photoDialog.close(); });



  // Controles delegados: siguen funcionando cuando PJAX reemplaza navegación.

  const closeMenu = () => {

    document.getElementById('navLinks')?.classList.remove('open');

    document.getElementById('navToggle')?.setAttribute('aria-expanded','false');

  };

  document.addEventListener('click', event => {

    const toggle = event.target.closest('#navToggle');

    if (toggle) {

      const open = document.getElementById('navLinks')?.classList.toggle('open');

      toggle.setAttribute('aria-expanded', String(Boolean(open)));

    } else if (event.target.closest('.navbar a') || !event.target.closest('.navbar')) closeMenu();

    const button = event.target.closest('[data-toggle-pw]');

    if (button) {

      const input = document.getElementById(button.dataset.togglePw);

      if (input) input.type = input.type === 'REPLACE_WITH_LOCAL_VALUE' ? 'text' : 'REPLACE_WITH_LOCAL_VALUE';

    }

  });

  document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenu(); });

  const syncRemember = () => {

    const remember = document.getElementById('recordar'), google = document.getElementById('google-recordar');

    if (remember && google) google.value = remember.checked ? '1' : '0';

  };

  syncRemember();

  document.addEventListener('change', syncRemember);

  document.addEventListener('musicmad:page', syncRemember);



  // Tema accesible: recuerda la elección y respeta la preferencia del sistema al iniciar.

  let savedTheme = '';

  try { savedTheme = localStorage.getItem('musicmad.theme') || ''; } catch (_) {}

  const applyTheme = (theme) => {

    const selected = theme === 'light' || theme === 'dark' ? theme : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

    document.documentElement.dataset.theme = selected;

    document.dispatchEvent(new CustomEvent('musicmad:theme',{detail:{theme:selected}}));

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => button.setAttribute('aria-pressed', String(selected === 'dark')));

    document.querySelectorAll('[data-theme-choice]').forEach(control => control.value = savedTheme || 'system');

  };

  applyTheme(savedTheme);

  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (!savedTheme || savedTheme === 'system') applyTheme(savedTheme); });

  document.addEventListener('musicmad:page', () => applyTheme(savedTheme));

  document.addEventListener('change', event => {

    if (!event.target.matches('[data-theme-choice]')) return;

    savedTheme = ['light','dark'].includes(event.target.value) ? event.target.value : 'system';

    try { localStorage.setItem('musicmad.theme', savedTheme); } catch (_) {}

    applyTheme(savedTheme);

  });

  document.addEventListener('click', (event) => {

    if (!event.target.closest('[data-theme-toggle]')) return;

    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';

    savedTheme = next;

    try { localStorage.setItem('musicmad.theme', next); } catch (_) {}

    applyTheme(next);

  });

  document.addEventListener('click', (event) => {

    if (event.target.closest('[data-pwa-install]')) event.preventDefault();

  });

  document.addEventListener('change', (event) => {

    const control = event.target.closest?.('[data-auto-submit]');

    if (control?.form?.requestSubmit) control.form.requestSubmit();

  });



  // Toasts reemplazan alertas intrusivas y se pueden cerrar con teclado o toque.

  const toastRoot = document.createElement('div');

  toastRoot.className = 'toast-stack';

  toastRoot.setAttribute('aria-live', 'polite');

  toastRoot.setAttribute('aria-relevant', 'additions');

  document.body.append(toastRoot);

  document.addEventListener('musicmad:toast', (event) => {

    const message = String(event.detail?.message || '').slice(0, 240);

    if (!message) return;

    if(event.detail?.id==='player-audio-error')toastRoot.querySelector('[data-toast-id="player-audio-error"]')?.remove();

    const item = document.createElement('div');

    if(event.detail?.id==='player-audio-error')item.dataset.toastId='player-audio-error';

    item.className = `app-toast toast-${event.detail?.type === 'error' ? 'error' : 'success'}`;

    item.setAttribute('role', 'status');

    const text = document.createElement('span'); text.textContent = message;

    const close = document.createElement('button'); close.type = 'button'; close.setAttribute('aria-label', 'Cerrar aviso'); close.textContent = '×';

    close.addEventListener('click', () => item.remove());

    item.append(text, close); toastRoot.append(item);

    setTimeout(() => item.remove(), 5000);

  });



  document.addEventListener('musicmad:playback-ok',()=>toastRoot.querySelector('[data-toast-id="player-audio-error"]')?.remove());

  // Avisos que se ocultan solos

  document.querySelectorAll('[data-autohide]').forEach((el) => {

    setTimeout(() => { el.classList.add('hidden'); }, Number(el.dataset.autohide) || 5000);

  });



  // Pestañas Servicios / Instrumentos (catálogo y admin)

  document.addEventListener('click', event => {

      const btn = event.target.closest('[data-tab]'); if (!btn) return;

      document.querySelectorAll('[data-tab]').forEach((b) => b.classList.toggle('active', b === btn));

      document.querySelectorAll('[data-panel]').forEach((p) => {

        p.classList.toggle('hidden', p.dataset.panel !== btn.dataset.tab);

      });

  });

});

