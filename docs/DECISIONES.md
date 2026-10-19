# Decisiones de revisión

## Historial ausente

La carpeta recibida no tenía `.git`. Tanto `git ls-files -- config/local.php` como `git log --all -- config/local.php` fallaron por esa causa. No se puede concluir si el archivo estuvo en otro repositorio o remoto. Se inició un historial local desde una lista explícita de código, sin leer ni agregar la configuración real. No se reescribió historial existente ni se contactaron remotos.

## Configuración y entrega

Prioridad: entorno del proceso, `.env` privado y finalmente constantes antiguas de `config/local.php`. Los consumidores de base de datos y OAuth resuelven esa prioridad sin redefinir constantes antiguas ni editar la configuración del propietario. QA usa exclusivamente sus constantes aisladas.

Los ZIP se construyen desde una lista de archivos y directorios de código, no desde una copia recursiva del proyecto. Los ejemplos `.env*` se documentan en Git pero también se excluyen del ZIP. Vendor se incluye por defecto porque el hosting compartido puede no ejecutar Composer; `--without-vendor` exige `composer install --no-dev` en el despliegue. El detector busca bloques PEM reales, no cadenas de delimitadores en bibliotecas criptográficas.
