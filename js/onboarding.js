(() => {
  const text=(key,params={})=>window.MusicMadText?window.MusicMadText(key,params):key.replace(/\{([a-z_]+)\}/g,(marker,name)=>String(params[name]??marker));
  const installed = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  function update() {
    const form = document.querySelector('[data-onboarding-form]');
    if (!form) return;
    const step = Number(form.elements.paso.value), counter = form.querySelector('[data-onboarding-counter]');
    if (counter) {
      const name = step === 2 ? 'generos[]' : 'musicos[]';
      const count = form.querySelectorAll(`input[name="${name}"]:checked`).length, maximum = step === 2 ? 6 : 12;
      counter.textContent = text('{count} seleccionados · mínimo 3, máximo {maximum}',{count,maximum});
      form.querySelector('[data-onboarding-next]').disabled = count < 3 || count > maximum;
    }
    const device = form.querySelector('[data-onboarding-device]');
    if (!device) return;
    form.elements.instalacion.value = installed() ? 'standalone' : 'instrucciones';
    const status = device.querySelector('[data-onboarding-install-status]');
    status.textContent = text(installed() ? 'MusicMad está abierta como aplicación instalada.' : 'Puedes instalarla desde el menú de tu navegador si no aparece el botón de instalación.');
    if (device.dataset.pushConfigured !== '1') form.elements.notificaciones.value = 'servicio_pendiente';
    else if (!('Notification' in window) || !('PushManager' in window)) form.elements.notificaciones.value = 'sin_soporte';
    else form.elements.notificaciones.value = device.dataset.pushBound === '1' ? 'activa' : Notification.permission === 'denied' ? 'bloqueada' : 'sin_activar';
  }
  document.addEventListener('musicmad:push-active', () => {
    const field = document.querySelector('[data-onboarding-form] input[name=notificaciones]');
    if (field) field.value = 'activa';
    const device = document.querySelector('[data-onboarding-device]');
    if (device) device.dataset.pushBound = '1';
  });
  document.addEventListener('change', event => {
    if (event.target.matches('[name="generos[]"],[name="musicos[]"]')) update();
  });
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!form.matches('[data-onboarding-form]')) return;
    if (Number(form.elements.paso.value) === 5) {
      form.elements.instalacion.value = installed() ? 'standalone' : 'instrucciones';
      if (form.elements.notificaciones.value !== 'activa' && 'Notification' in window && Notification.permission === 'denied') form.elements.notificaciones.value = 'bloqueada';
    }
  }, true);
  window.addEventListener('appinstalled', update);
  document.addEventListener('musicmad:page', update);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', update, {once:true}); else update();
})();
