<?php

if (PHP_SAPI !== 'cli' || getenv('MM_RUN_QA') !== '1') exit(2);
require __DIR__ . '/bootstrap.php';
if (DB_NAME !== 'musicmad_qa') exit(2);
if (in_array($argv[1] ?? '', ['create','create-public','create-complete'], true)) {
    $ids = [];
    $REPLACE_WITH_LOCAL_VALUE = 'MusicMad_Onboard_Mobile_QA_42!';
    foreach (['Principal','Guitarra','Voz','Batería'] as $name) {
        $email = 'onboarding-mobile-' . bin2hex(random_bytes(10)) . '@musicmad.invalid';
        getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute(['OnboardQA',$name,$email,REPLACE_WITH_LOCAL_VALUE_hash($REPLACE_WITH_LOCAL_VALUE,PASSWORD_BCRYPT)]);
        $ids[] = $uid = (int) getDB()->lastInsertId();
        marcarCorreoGoogleVerificado($uid,$email,true);
        if (count($ids)===1) $main = ['uid'=>$uid,'email'=>$email,'REPLACE_WITH_LOCAL_VALUE'=>$REPLACE_WITH_LOCAL_VALUE];
    }
    if (($argv[1] ?? '') === 'create-public') {
        $slug = 'qr_mobile_' . $ids[0];
        getDB()->prepare('INSERT INTO perfiles_publicos(usuario_id,slug) VALUES(?,?)')->execute([$ids[0],$slug]);
        getDB()->prepare("INSERT INTO musicmad_epk_options(usuario_id,habilitado,color) VALUES(?,1,'violeta')")->execute([$ids[0]]);
        $main['slug'] = $slug;
    }
    if (($argv[1] ?? '') === 'create-complete') {
        getDB()->prepare('INSERT INTO musicmad_onboarding_required(usuario_id,paso,completado_en) VALUES(?,6,NOW())')->execute([$ids[0]]);
    }
    echo json_encode($main + ['targets'=>array_slice($ids,1),'ids'=>$ids]);
} elseif (($argv[1] ?? '') === 'publish-profile') {
    $uid=(int)($argv[2]??0);
    $query=getDB()->prepare("SELECT id FROM usuarios WHERE id=? AND email LIKE 'onboarding-mobile-%@musicmad.invalid'");
    $query->execute([$uid]);if(!$query->fetchColumn())exit(2);
    $slug='apache_qa_'.$uid;
    getDB()->prepare('INSERT INTO perfiles_publicos(usuario_id,slug) VALUES(?,?)')->execute([$uid,$slug]);
    getDB()->prepare("INSERT INTO musicmad_epk_options(usuario_id,habilitado,color) VALUES(?,1,'violeta')")->execute([$uid]);
    getDB()->prepare('INSERT INTO musicmad_epk_embed(usuario_id,permitido) VALUES(?,1)')->execute([$uid]);
    echo json_encode(['alias'=>$slug]);
} elseif (($argv[1] ?? '') === 'media-list') {
    $uid=(int)($argv[2]??0);
    $query=getDB()->prepare("SELECT p.id,p.titulo FROM publicaciones p JOIN usuarios u ON u.id=p.usuario_id WHERE u.id=? AND u.email LIKE 'onboarding-mobile-%@musicmad.invalid'");
    $query->execute([$uid]);echo json_encode($query->fetchAll(PDO::FETCH_ASSOC));
} elseif (($argv[1] ?? '') === 'cleanup') {
    foreach (array_slice($argv,2) as $uid) {
        if (!ctype_digit($uid)) exit(2);
        getDB()->prepare("DELETE FROM usuarios WHERE id=? AND email LIKE 'onboarding-mobile-%@musicmad.invalid'")->execute([(int)$uid]);
    }
} else exit(2);
