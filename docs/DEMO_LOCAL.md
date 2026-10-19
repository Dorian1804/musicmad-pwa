# Datos ficticios para desarrollo

El seeder es opcional y exclusivo de una base **local separada**. No se ejecuta al abrir MusicMad, importar una migración ni desplegar una actualización. No lo ejecutes en la base del sitio publicado.

## Preparación

1. Crea una base vacía local llamada `musicmad_demo`. Aplica el esquema inicial y las migraciones en esa base. El instalador inicial recrea tablas: úsalo solamente en esta base nueva y vacía.
2. Configura la copia de desarrollo para conectar a `musicmad_demo` mediante las variables privadas habituales `MM_DB_HOST`, `MM_DB_NAME`, `MM_DB_USER` y `MM_DB_PASS`. El host debe ser `localhost`, `127.0.0.1` o `::1`; puede incluir `;port=...`.
3. Desde la raíz de esa copia de desarrollo, ejecuta:

```text
php bin/seed-demo.php --allow-demo --confirm=musicmad_demo
```

El comando exige que el nombre confirmado coincida tanto con la configuración como con la base realmente conectada. Solo acepta nombres `musicmad_*demo` o `musicmad_*qa`. `REPLACE_WITH_LOCAL_VALUE`, un host remoto, una confirmación distinta o la ausencia de `--allow-demo` se rechazan.

## Qué crea

- Tres músicos ficticios: Luna, Leo y Nora, identificados como Demo y con direcciones reservadas `@musicmad.invalid`.
- Nueve publicaciones de texto, tres comentarios, tres aplausos y tres seguimientos entre esas mismas cuentas.
- Instrumentos, géneros, ciudades y avatares de la colección local.

Cada cuenta tiene una contraseña aleatoria independiente que se descarta. No hay contraseña común, cuenta administradora, correo enviado, descarga externa ni archivo multimedia creado. Para explorar la interfaz, registra tu propia cuenta de desarrollo.

La operación usa una transacción y un bloqueo por base. Repetirla conserva la demo completa sin duplicarla. Una cuenta que ocupa un identificador de demo, una demo parcial o una cuenta editada detiene el proceso; no sustituye ni completa automáticamente datos existentes. No ofrece un comando de borrado.

## Verificación realizada

Pruebas en `musicmad_qa` comprobaron destino, opt-in, repetición, colisiones, exclusión entre dos conexiones y rollback al provocar un fallo a mitad de la inserción. Se conservó la cuenta QA existente y se retiraron únicamente las filas ficticias creadas por estas pruebas. No se ejecutó el comando con la configuración real ni contra el hosting.
