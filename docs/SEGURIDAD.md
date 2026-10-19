# Revisión de seguridad

## Acciones manuales obligatorias del propietario

- Rotar el secreto de Google OAuth y la contraseña de MySQL en sus paneles. Esta revisión no puede hacerlo por el propietario.
- Actualizar únicamente la configuración privada y revocar los valores anteriores. No pegar valores en tickets, commits o ZIP.
- Si existe un repositorio remoto o una copia antigua con historial, revisar allí la exposición y gestionar la retirada de secretos; el historial anterior no estaba disponible en esta carpeta.
- Comprobar permisos y persistencia del almacén privado, copias externas, servicio real de correo y reglas de Apache en el hosting. Las pruebas locales no activan servicios del proveedor.

## Empaquetado

`bin/package.sh --output /ruta/externa/entrega.zip` o `bin/package.ps1 -Output C:/ruta/externa/entrega.zip`. El destino debe existir como directorio y quedar fuera del proyecto; no se sobrescriben entregas. `--without-vendor`/`-WithoutVendor` solo para despliegues que instalan dependencias. Verificación: `php bin/package.php --verify /ruta/externa/entrega.zip`.

El paquete excluye secretos, ejemplos `.env*`, datos de usuarios, respaldos, staging, herramientas de pruebas, documentación privada y ZIP anidados. La detección preventiva no sustituye la rotación de credenciales.

## Auditoría de subidas y sesiones

Revisados `subir-archivo.php`, `colab-subir.php`, transporte, avatares, almacén, formas de onda y endpoint de imágenes. Se mantienen MIME con Fileinfo, firmas y getID3; nombres aleatorios; archivos privados; bloques de 512 KiB con SHA-256; cuatro subidas activas por cuenta, 512 MiB pendientes y 40 inicios por hora. Audio 64 MiB, video 256 MiB y PDF 20 MiB. Las fotos raster se decodifican y vuelven a codificar como JPEG: no se conserva el contenedor ni sus metadatos originales.

Correcciones: rechazo de tipos de medio desconocidos y de streams/rutas no regulares antes del analizador; tamaño real antes de getID3; duración conocida positiva y finita hasta seis horas; video hasta 8192 por lado y 32 megapíxeles; estimación conservadora de memoria antes de GD para avatar, portada y mercado. FFmpeg sigue siendo solo CLI, binario absoluto fuera del proyecto, argumentos separados sin shell, protocolos file/pipe, un hilo y terminación por presupuesto de tiempo. Se limita cada asignación de FFmpeg a 64 MiB; **no es un límite del consumo total del proceso** (véase https://ffmpeg.org/ffmpeg.html).

Pruebas nuevas primero fallaron: un tipo desconocido era aceptado y la URL remota llegaba hasta la configuración del binario; faltaban políticas verificables de duración/memoria. Tras corregirlo pasan esos casos y las regresiones de transporte/almacén.

Sesiones: Secure en producción, HttpOnly, SameSite=Lax, modo estricto, solo cookies, regeneración al autenticar y registro/revocación de dispositivos con expiración. Login y recuperación tienen límites; los POST web usan token de formulario o el mismo token por cabecera para JSON/bloques. La API con Bearer JWT no se autentica por cookie y no incorpora CSRF de formulario: es una excepción deliberada, no un bypass de sesión. No se cambió el hash bcrypt ni la duración de sesiones para evitar un cambio de comportamiento no pedido; Argon2id queda opcional tras medir memoria/tiempo en el hosting.

### Riesgos residuales y decisiones conservadoras

- getID3 se ejecuta en PHP: desactivar tags/adjuntos y limitar tamaño reduce exposición, pero no garantiza un tiempo máximo duro por archivo ni aislamiento del parser. El hosting debe mantener PHP, getID3 y códecs actualizados, con límites de proceso. Aislarlo exige disponibilidad de procesos externos o un servicio separado: no se presume disponible.
- Algunos WebM de streaming no declaran duración. Se conservó su compatibilidad; el límite de seis horas solo puede verificarse cuando existe ese metadato. Una política de rechazo absoluto cambiaría publicaciones válidas y requiere decisión del producto.
- El cálculo previo de GD es una estimación, no una garantía frente a bibliotecas vulnerables o memoria ajena al contador de PHP. El proveedor debe imponer límites globales.
- Las cuotas de staging no equivalen a una cuota total de almacenamiento publicado. El propietario debe vigilar espacio, retención de copias y límites del proveedor; no se inventó una cuota comercial ni se eliminaron archivos para liberar espacio.
- Las imágenes referenciadas de avatar/portada/mercado mantienen su política pública existente. Los audios/videos/PDF siguen comprobando permisos en sus endpoints. No se modificó privacidad visible durante esta auditoría.
- Validación local no sustituye comprobar HTTPS/proxy, correo, permisos del disco y límites efectivos del hosting.
