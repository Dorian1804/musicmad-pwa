# MusicMad PWA

Aplicacion web en PHP, JavaScript y CSS, con base de datos MySQL, acceso opcional con Google y funciones sociales para musica.

## Configuracion local

1. Instala las dependencias declaradas en `composer.json` con Composer.
2. Copia `.env.example` a `.env` y configura tus valores locales. No publiques `.env` ni `config/local.php`.
3. Configura la conexion MySQL y, si los utilizas, Google OAuth, correo y Web Push mediante las variables de entorno documentadas en `.env.example`.
4. `config/schema.sql` contiene un esquema estructural recuperado sin registros personales. Revisa las migraciones necesarias para tu version antes de usarlo.

## Alcance de esta copia

Corresponde exclusivamente a la version seleccionada por el propietario. Se publica con un historial nuevo; no contiene configuracion privada, secretos, archivos de usuarios, dependencias instaladas ni volcados con datos. Algunos archivos detectados por la revision de secretos pueden haberse omitido; consulta `PUBLICATION-NOTES.md`. Tambien se omiten imagenes y otros medios originales por privacidad. No es una entrega lista para produccion y no se ha validado la aplicacion completa.

No se concede una licencia adicional sin confirmar los derechos de los recursos originales.
