# React · Controles de historias de MusicMad

Fuente recuperada de la versión local de desarrollo de MusicMad. Esta carpeta documenta el trabajo con React y puede compilarse de manera independiente. No activa automáticamente historias React en la instantánea PHP publicada en la raíz.

## Compilar

Node.js 22 o posterior y pnpm:

```powershell
pnpm install --frozen-lockfile
pnpm run build
pnpm test
```

El resultado queda en `dist/story-chrome.min.js`, excluido de Git.

La prueba con JSDOM comprueba montaje, callback de cierre y restauración del HTML al desmontar. No sustituye las pruebas del reproductor completo en un navegador.

## Contrato de integración

El bundle expone `window.MusicMadStoryChrome.mount(host, actions, catalogue)`.

- `host`: elemento DOM que contiene los controles; su HTML original se conserva para restaurarlo al desmontar.
- `actions`: callbacks `close`, `previous`, `next` y `control` proporcionados por el reproductor.
- `catalogue`: mapa opcional de traducciones; las claves ausentes conservan su texto original.

La instancia ofrece `show(count, position)`, `control(icon, label)`, `feedback(text)`, `progress(value)` y `unmount()`. La posición empieza en cero y el progreso usa valores de 0 a 1.

## Alcance

Contiene segmentos de progreso, controles, navegación, feedback accesible y sincronización con un almacén externo mediante `useSyncExternalStore`. El controlador de reproducción, los estilos y la integración con las páginas PHP pertenecen a la versión local completa. Para incorporarlo a esta instantánea se deben migrar y probar esas piezas juntas. No se incluyen datos de usuarios ni configuración de producción.
