# MusicMad

Plataforma web para conectar músicos y organizar su actividad musical. Combina perfiles, publicaciones, bandas, eventos y comunicación en una aplicación progresiva.

## Funciones presentes en el código

- Perfiles musicales y búsqueda de músicos.
- Publicaciones, comentarios, reacciones y clips.
- Bandas, colaboraciones y eventos.
- Chat, salas de escucha y notificaciones.
- Inicio de sesión, configuración de cuenta y herramientas de moderación.
- Manifiesto PWA, service worker y pantalla sin conexión.

La disponibilidad real de cada función depende de la configuración y del estado de esta copia.

## Tecnologías

PHP, JavaScript, CSS y MySQL; dependencias PHP gestionadas con Composer. La versión local de desarrollo también trabaja con React; esta copia pública conserva una instantánea anterior y no incluye todavía ese frontend.

## Arquitectura

| Carpeta / archivo | Responsabilidad |
|---|---|
| Archivos PHP de la raíz | Páginas y acciones del servidor |
| `api/` | Contratos y endpoints |
| `includes/` | Funciones compartidas y módulos |
| `config/` | Ejemplos de configuración y esquema estructural |
| `js/` y `CSS/` | Interacción y estilos |
| `assets/` | Recursos disponibles de la interfaz |
| `tests/` | Herramientas y pruebas recuperadas |
| `manifest.json` y `sw.js` | Instalación y ciclo de vida de la PWA |

## Preparación local

1. Instala PHP compatible con los requisitos de `composer.lock`, Composer y MySQL/MariaDB. Comprueba las extensiones exigidas por Composer.
2. Ejecuta `composer install`.
3. Copia `.env.example` a `.env` y configura `MM_DB_HOST`, `MM_DB_NAME`, `MM_DB_USER` y `MM_DB_PASS` con una base de pruebas.
4. Revisa `config/schema.sql` antes de importarlo. Es un esquema recuperado, no una garantía de que estén todas las migraciones de producción.
5. Configura Google OAuth, SMTP y Web Push solo si utilizarás esas integraciones; sus variables están descritas en `.env.example`.
6. Para explorar localmente, ejecuta `php -S localhost:8000` desde la raíz. Para comprobar reglas `.htaccess` y el despliegue, utiliza Apache con HTTPS.

## Estado de la publicación

Esta instantánea se publicó sin configuración privada, archivos de usuarios, medios personales ni historial del servidor. Puede faltar código o recursos retenidos durante aquella revisión. Consulta [PUBLICATION-NOTES.md](PUBLICATION-NOTES.md). No se ha ejecutado una prueba integral de esta instantánea con base de datos en esta revisión.

## Configuración y secretos

`.env` y `config/local.php` son privados. Usa valores propios en tu entorno y datos ficticios al probar. Las claves de Google, SMTP y VAPID no forman parte del código publicable.

## Recorrido sugerido

Empieza por `index.php`, continúa con `feed.php` y `perfil.php`, y revisa después los módulos compartidos en `includes/`. Para contribuir, explica el problema, el cambio propuesto y cómo lo verificaste.
