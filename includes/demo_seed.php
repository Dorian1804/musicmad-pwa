<?php
/** Explicitly requested fixture data for a separate development database. Never media or credentials. */
function validarDestinoDemoMusicMad(string $database, string $confirmation, string $host, bool $enabled): void
{
    $loopback = preg_match('/^(?:localhost|127\.0\.0\.1|\[?::1\]?)(?:;port=[0-9]{1,5})?$/iD', $host) === 1;
    if (!$enabled || !$loopback || !preg_match('/^musicmad_[a-z0-9_]*(?:demo|qa)$/D', $database)
        || !hash_equals($database, $confirmation)) {
        throw new DomainException('Solo se permiten bases locales musicmad_*demo o musicmad_*qa con confirmación explícita.');
    }
}
function perfilesDemoMusicMad(): array
{
    return [
        ['email'=>'luna.seed-v1@musicmad.invalid','nombre'=>'Luna','apellido'=>'Demo','instrumento'=>'Guitarra','generos'=>'Rock, Indie','ciudad'=>'Guadalajara','avatar'=>'img/avatares/musicos/m2.svg'],
        ['email'=>'leo.seed-v1@musicmad.invalid','nombre'=>'Leo','apellido'=>'Demo','instrumento'=>'Batería','generos'=>'Jazz, Blues','ciudad'=>'Guadalajara','avatar'=>'img/avatares/musicos/m4.svg'],
        ['email'=>'nora.seed-v1@musicmad.invalid','nombre'=>'Nora','apellido'=>'Demo','instrumento'=>'Voz, teclado','generos'=>'Pop, Electrónica','ciudad'=>'Ciudad de México','avatar'=>'img/avatares/musicos/m6.svg'],
    ];
}
function sembrarDemoMusicMad(PDO $db, string $confirmation, string $host, bool $enabled): array
{
    $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    validarDestinoDemoMusicMad($database, $confirmation, $host, $enabled);
    if ($db->inTransaction()) throw new LogicException('Finaliza la operación anterior antes de sembrar la demo.');
    $required = ['usuarios','perfiles_musicales','publicaciones','comentarios','aplausos','seguidores'];
    $st = $db->prepare('SELECT table_name,engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?,?,?,?)');
    $st->execute($required); $tables = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($required as $table) if (($tables[$table]??'')!=='InnoDB') throw new RuntimeException('Aplica el esquema social InnoDB antes de sembrar.');
    $lock = 'musicmad_demo_'.substr(hash('sha256', $database),0,32);
    $st=$db->prepare('SELECT GET_LOCK(?,0)'); $st->execute([$lock]);
    if ((int)$st->fetchColumn()!==1) throw new RuntimeException('Otra siembra de demo está en curso.');
    $marker = '[MusicMad demo v1] Cuenta ficticia, sin acceso compartido.';
    try {
        $db->beginTransaction(); $profiles=perfilesDemoMusicMad(); $found=[];
        $st=$db->prepare('SELECT id,nombre,apellido,bio,rol,activo FROM usuarios WHERE email=? FOR UPDATE');
        foreach ($profiles as $profile) {
            $st->execute([$profile['email']]); $row=$st->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;
            if ($row['nombre']!==$profile['nombre'] || $row['apellido']!==$profile['apellido'] || $row['bio']!==$marker
                || $row['rol']!=='cliente' || (int)$row['activo']!==1) throw new DomainException('Un identificador de demo ya está ocupado. No se modificó esa cuenta.');
            $found[]=(int)$row['id'];
        }
        if (count($found)===count($profiles)) { $db->commit(); return ['estado'=>'existente','usuarios'=>$found,'publicaciones'=>0]; }
        if ($found) throw new DomainException('La demo está incompleta o fue editada. No se rellenan ni sustituyen cuentas existentes.');
        $ids=[]; $posts=[];
        $user=$db->prepare("INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE,avatar,bio,rol,activo) VALUES(?,?,?,?,?,?,'cliente',1)");
        $musician=$db->prepare('INSERT INTO perfiles_musicales(usuario_id,instrumento,generos,ciudad) VALUES(?,?,?,?)');
        $post=$db->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo,texto,genero,created_at) VALUES(?,'texto',?,?,?,DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? HOUR))");
        foreach ($profiles as $index=>$profile) {
            // Each account has a unique, discarded random REPLACE_WITH_LOCAL_VALUE. No shared default login.
            $hash=REPLACE_WITH_LOCAL_VALUE_hash(bin2hex(random_bytes(32)),PASSWORD_BCRYPT);
            if (!is_string($hash)) throw new RuntimeException('No se pudo preparar la cuenta ficticia.');
            $user->execute([$profile['nombre'],$profile['apellido'],$profile['email'],$hash,$profile['avatar'],$marker]);
            $id=(int)$db->lastInsertId(); $ids[]=$id;
            $musician->execute([$id,$profile['instrumento'],$profile['generos'],$profile['ciudad']]);
            foreach (['Mi rincón musical','¿Ensayamos esta semana?','Ideas para la próxima colaboración'] as $n=>$title) {
                $genre=explode(', ',$profile['generos'])[0];
                $post->execute([$id,$title.' · '.$profile['nombre'].' Demo','Publicación ficticia para explorar MusicMad. Toco '.$profile['instrumento'].' y me gusta el '.$genre.'. #MusicMadDemo',$genre,1+$index*3+$n]);
                $posts[]=(int)$db->lastInsertId();
            }
        }
        $comment=$db->prepare('INSERT INTO comentarios(publicacion_id,usuario_id,texto) VALUES(?,?,?)');
        $applause=$db->prepare('INSERT INTO aplausos(usuario_id,publicacion_id) VALUES(?,?)');
        $follow=$db->prepare('INSERT INTO seguidores(seguidor_id,seguido_id) VALUES(?,?)');
        foreach ($ids as $index=>$id) {
            $other=$ids[($index+1)%count($ids)];
            $follow->execute([$id,$other]); $applause->execute([$other,$posts[$index*3]]);
            $comment->execute([$posts[$index*3],$other,'Comentario ficticio: me apunto a compartir ideas.']);
        }
        $db->commit();
        return ['estado'=>'creada','usuarios'=>$ids,'publicaciones'=>count($posts)];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    } finally { $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
}
