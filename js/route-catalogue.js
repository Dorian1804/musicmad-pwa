// Generated from includes/routing/catalogue.php. Never grants server permissions.
(() => {
 const base = new URL('../', document.currentScript.src);
 const pages = ["^/configuracion/archivos$","^/error/404$","^/error/500$","^/avisos\\-dispositivo$","^/banda/[a-f0-9]{32}/chat$","^/banda/[a-f0-9]{32}/calendario\\.ics$","^/banda/[a-f0-9]{32}$","^/bandas$","^/bienvenida$","^/buscar$","^/busco$","^/evento/[a-f0-9]{32}/cartel$","^/clip/[a-f0-9]{32}$","^/clips$","^/colab/[a-f0-9]{32}$","^/colabs$","^/compartir$","^/compatibles$","^/configuracion$","^/confirmar\\-correo$","^/datos$","^/dos\\-pasos$","^/embed/@[a-z][a-z0-9_]{2,29}$","^/epk$","^/estadisticas$","^/evento/[a-f0-9]{32}/calendario\\.ics$","^/evento/[a-f0-9]{32}$","^/eventos$","^/feed$","^/auth/google/retorno$","^/$","^/listas$","^/login$","^/logros$","^/manifest\\.webmanifest$","^/mapa$","^/mensajes$","^/menu$","^/mercado$","^/moderacion$","^/musico/[a-f0-9]{32}$","^/musicos$","^/notificaciones$","^/pendientes$","^/estilos/perfiles$","^/perfil$","^/permisos$","^/listas/equipo$","^/lista/[a-f0-9]{32}/equipo$","^/@[a-z][a-z0-9_]{2,29}$","^/publicacion/[a-f0-9]{32}$","^/reactivar\\-cuenta$","^/recomendaciones$","^/recuperar$","^/registro$","^/restablecer$","^/restaurar\\-video$","^/reto/[a-f0-9]{32}$","^/retos$","^/sala/[a-f0-9]{32}$","^/salas$","^/seguridad$","^/ubicacion$","^/verificaciones$","^/verificar\\-correo$","^/mensajes/[a-f0-9]{32}$"].map(value => new RegExp(value));
 const posts = ["^/acciones/banda$","^/acciones/bienvenida$","^/acciones/clip$","^/acciones/colab$","^/configuracion$","^/epk$","^/acciones/evento$","^/acciones/feedback$","^/login$","^/mensajes$","^/notificaciones$","^/perfil$","^/acciones/playlist\\-equipo$","^/acciones/recomendacion$","^/registro$","^/acciones/repost$","^/acciones/reto$","^/acciones/sala$","^/acciones/social$","^/ubicacion$","^/verificaciones$","^/mensajes/[a-f0-9]{32}$"].map(value => new RegExp(value));
 const refresh = ["/bandas","/buscar","/busco","/eventos","/feed","/mercado","/musicos","/notificaciones"];
 const relative = value => {
  try { const url = new URL(value, document.baseURI);
   return url.origin === base.origin && url.pathname.startsWith(base.pathname) ? '/' + url.pathname.slice(base.pathname.length) : null;
  } catch (_) { return null; }
 };
 window.MusicMadRoutePaths = Object.freeze({
  isPage: value => { const path = relative(value); return path !== null && pages.some(regex => regex.test(path)); },
  isPost: value => { const path = relative(value); return path !== null && posts.some(regex => regex.test(path)); },
  canRefresh: value => refresh.includes(relative(value))
 });
})();
