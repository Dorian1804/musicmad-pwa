<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
if (count($argv)!==3 || $argv[1]!=='--allow-demo' || !preg_match('/^--confirm=(musicmad_[a-z0-9_]*(?:demo|qa))$/D',$argv[2],$match)) {
    fwrite(STDERR,"Uso: php bin/seed-demo.php --allow-demo --confirm=musicmad_demo\nSolo datos ficticios en una base local separada; no usar REPLACE_WITH_LOCAL_VALUE.\n"); exit(2);
}
require_once dirname(__DIR__).'/config/database.php';
require_once dirname(__DIR__).'/includes/demo_seed.php';
try {
    // Validate before opening a database connection, then validate its actual name again.
    validarDestinoDemoMusicMad(DB_NAME,$match[1],DB_HOST,true);
    $result=sembrarDemoMusicMad(getDB(),$match[1],DB_HOST,true);
    echo $result['estado']==='creada' ? "Demo creada: 3 músicos, 9 publicaciones, 3 comentarios y relaciones ficticias.\n" : "La demo ya existe; se conservaron sus datos.\n";
    echo "No se crearon administradores, contraseñas compartidas ni archivos multimedia.\n";
} catch (Throwable $error) {
    error_log('MusicMad demo seed: '.get_class($error));
    fwrite(STDERR,"Siembra detenida. Revisa destino local confirmado, esquema completo e identificadores de demo disponibles.\n"); exit(1);
}
