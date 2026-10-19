// ============================================================
//  MusicMad – Vista previa en vivo de "Mi perfil"
//  js/perfil.js
//
//  Basado en la lógica de la actividad 1.4 (generador de
//  perfiles), pero SIN el bloque de autocompletado por API de
//  GitHub: aquí solo se eligen avatares predefinidos guardados
//  en el propio servidor y los datos se guardan en la base de
//  datos de MusicMad (ver perfil.php / includes/auth.php).
// ============================================================

const initializeProfileEditor = () => {
  const text=key=>window.MusicMadText?window.MusicMadText(key):key;
  const dom = {
    bio: document.getElementById('input-bio'),
    color: document.getElementById('input-color'),
    avatarInput: document.getElementById('input-avatar'),
    avatars: document.getElementById('avatar-selector'),
    photoUpload: document.getElementById('photo-upload'),
    coverUpload: document.getElementById('cover-upload'),
    coverInput: document.getElementById('input-portada'),
    covers: document.getElementById('portada-selector'),

    previewName: document.getElementById('name-preview'),
    previewRole: document.getElementById('role-preview'),
    instrumento: document.getElementById('input-instrumento'),
    previewBio: document.getElementById('bio-preview'),
    previewAvatar: document.getElementById('avatar-preview'),
    previewHeader: document.getElementById('preview-header'),
  };

  if (!dom.avatars || dom.avatars.dataset.initialized === '1') return;
  dom.avatars.dataset.initialized = '1';
  const coverPhoto = document.getElementById('preview-cover-photo');

  let photoPreviewUrl = null;
  let coverPreviewUrl = null;
  const form = document.getElementById('form-perfil');
  const preparedPhotos = new Map();
  let pendingPhotos = 0;
  const disabledBefore = new Map();
  const setPhotoBusy = busy => {
    pendingPhotos += busy ? 1 : -1;
    if (pendingPhotos > 0) {
      form?.setAttribute('aria-busy', 'true');
      document.querySelectorAll('button[type=submit]').forEach(button => {
        if (button.form !== form) return;
        if (!disabledBefore.has(button)) disabledBefore.set(button, button.disabled);
        button.disabled = true;
      });
    } else {
      form?.removeAttribute('aria-busy');
      disabledBefore.forEach((disabled, button) => { button.disabled = disabled; });
      disabledBefore.clear();
    }
  };
  form?.addEventListener('submit', event => {
    if (pendingPhotos > 0) { event.preventDefault(); event.stopImmediatePropagation(); }
  }, true);
  form?.addEventListener('formdata', event => {
    preparedPhotos.forEach((file, input) => event.formData.set(input.name, file, file.name));
  });
  const publicImageUrl = (path) => {
    const match = /^uploads\/(avatars|covers|market)\/([A-Za-z0-9_-]+\.jpg)$/.exec(path);
    if (!match) return path;
    const valid = match[1] === 'market' ? /^[a-f0-9]{24}\.jpg$/.test(match[2])
      : /^[1-9][0-9]*_[a-f0-9]{24}\.jpg$/.test(match[2]);
    if (!valid) return path;
    const endpoint = new URL('media-image.php', document.baseURI);
    endpoint.searchParams.set('kind', match[1]);
    endpoint.searchParams.set('name', match[2]);
    return endpoint.href;
  };
  const pickerMessage = (input, message) => {
    input.setCustomValidity(message);
    input.reportValidity();
    input.setCustomValidity('');
    input.value = '';
  };

  async function optimizePhoto(input, file, targetWidth, targetHeight) {
    if (!window.MusicMadCropPhoto) throw new Error(text('El editor de fotos no pudo cargar. Recarga la página.'));
    preparedPhotos.delete(input);
    setPhotoBusy(true);
    try {
      const optimized = await window.MusicMadCropPhoto(file, { width: targetWidth, height: targetHeight });
      if (!optimized) { input.value = ''; return null; }
      // FormData is authoritative even when Safari refuses replacing the native FileList.
      preparedPhotos.set(input, optimized);
      try {
        const transfer = new DataTransfer(); transfer.items.add(optimized); input.files = transfer.files;
      } catch (error) {
        if (typeof FormDataEvent === 'undefined') {
          preparedPhotos.delete(input);
          throw new Error(text('Actualiza tu navegador para usar el recorte de fotos.'));
        }
      }
      return optimized;
    } finally { setPhotoBusy(false); }
  }

  const coverSource = () => {
    const value = dom.coverInput?.value || '';
    return value.startsWith('img/portadas/') ? value : (value ? `uploads/covers/${value}` : '');
  };

  function renderCard() {
    dom.previewBio.textContent = dom.bio.value || text('La biografía aparecerá aquí...');
    dom.previewAvatar.src = photoPreviewUrl || publicImageUrl(dom.avatarInput.value);
    document.querySelector('[data-preview-accent]')?.setAttribute('fill', dom.color.value);
    if (dom.coverInput) {
      const source = coverPreviewUrl || coverSource();
      if (coverPhoto) coverPhoto.src = publicImageUrl(source) || 'img/portadas/portada-01.svg';
      document.querySelectorAll('.portada-option').forEach((button) => {
        const selected = button.dataset.url === dom.coverInput.value;
        button.classList.toggle('selected', selected);
        button.setAttribute('aria-pressed', String(selected));
      });
    }

    document.querySelectorAll('.avatar-option').forEach((img) => {
      const selected = img.dataset.url === dom.avatarInput.value;
      img.classList.toggle('selected', selected); img.setAttribute('aria-pressed', String(selected));
    });
  }

  document.querySelectorAll('.avatar-option').forEach((img) => {
    img.addEventListener('click', () => {
      photoPreviewUrl = null;
      preparedPhotos.delete(dom.photoUpload);
      if (dom.photoUpload) dom.photoUpload.value = '';
      dom.avatarInput.value = img.dataset.url;
      renderCard();
    });
  });

  dom.photoUpload?.addEventListener('change', async () => {
    const file = dom.photoUpload.files?.[0];
    if (!file) return;
    let optimized;
    try { optimized = await optimizePhoto(dom.photoUpload, file, 512, 512, 5 * 1024 * 1024); }
    catch (error) { pickerMessage(dom.photoUpload, error.message); return; }
    if (!optimized || !dom.photoUpload.isConnected) return;
    if (photoPreviewUrl) URL.revokeObjectURL(photoPreviewUrl);
    photoPreviewUrl = URL.createObjectURL(optimized);
    document.querySelectorAll('.avatar-option').forEach((img) => img.classList.remove('selected'));
    renderCard();
  });

  document.querySelector('[data-open-avatar]')?.addEventListener('click', () => dom.photoUpload?.click());
  document.querySelector('[data-open-cover]')?.addEventListener('click', () => dom.coverUpload?.click());
  dom.coverUpload?.addEventListener('change', async () => {
    const file = dom.coverUpload.files?.[0];
    if (!file) return;
    let optimized;
    try { optimized = await optimizePhoto(dom.coverUpload, file, 1500, 600, 8 * 1024 * 1024); }
    catch (error) { pickerMessage(dom.coverUpload, error.message); return; }
    if (!optimized || !dom.coverUpload.isConnected) return;
    if (coverPreviewUrl) URL.revokeObjectURL(coverPreviewUrl);
    coverPreviewUrl = URL.createObjectURL(optimized);
    dom.coverInput.value = '';
    document.querySelectorAll('.portada-option').forEach((button) => {
      button.classList.remove('selected');
      button.setAttribute('aria-pressed', 'false');
    });
    const label = document.querySelector('.profile-cover-edit span');
    if (label) label.textContent = 'Portada lista · Guardar perfil';
    renderCard();
  });

  document.querySelectorAll('.portada-option').forEach((button) => {
    button.addEventListener('click', () => {
      if (coverPreviewUrl) URL.revokeObjectURL(coverPreviewUrl);
      coverPreviewUrl = null;
      preparedPhotos.delete(dom.coverUpload);
      if (dom.coverUpload) dom.coverUpload.value = '';
      dom.coverInput.value = button.dataset.url;
      const label = document.querySelector('.profile-cover-edit span');
      if (label) label.textContent = 'Cambiar portada';
      renderCard();
    });
  });

  if (dom.instrumento) {
    dom.instrumento.addEventListener('input', () => {
      dom.previewRole.textContent = dom.instrumento.value.trim() || text('Músico');
    });
  }
  dom.bio.addEventListener('input', renderCard);
  dom.color.addEventListener('input', renderCard);

  const release = () => {
    if (photoPreviewUrl) URL.revokeObjectURL(photoPreviewUrl);
    if (coverPreviewUrl) URL.revokeObjectURL(coverPreviewUrl);
    document.removeEventListener('musicmad:beforepage', release);
  };
  document.addEventListener('musicmad:beforepage', release);
  renderCard();
};
document.addEventListener('DOMContentLoaded', initializeProfileEditor, { once: true });
document.addEventListener('musicmad:page', initializeProfileEditor);
