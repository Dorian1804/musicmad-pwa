# Alcance de la publicacion

Se publica solo la version seleccionada por el propietario, con un primer commit nuevo.

- No se incluyen `config/local.php`, archivos `.env` reales, claves de Google ni contrasenas de bases de datos, correo o Web Push.
- Las coincidencias con valores de la configuracion privada se sustituyeron por marcadores en las copias. Los ejemplos requieren valores propios.
- Los volcados SQL con registros no se publican; `config/schema.sql` contiene solo instrucciones estructurales recuperadas. Las migraciones SQL originales se omitieron y deben revisarse antes de desplegar.
- Se omiten imagenes, archivos de usuarios, almacenamiento, copias de seguridad, dependencias instaladas y el historial Git anterior.
- Se revisaron las coincidencias del detector. El encabezado PEM construido alrededor de una clave generada en tiempo de ejecucion en `includes/push_controls.php` es codigo de formato, no una clave incrustada.
- Las pruebas incluyen datos ficticios. No se ejecutaron pruebas que conecten con servicios externos o bases de datos. La publicacion no certifica el funcionamiento completo de la aplicacion.
