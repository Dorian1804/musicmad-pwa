<?php
require_once __DIR__.'/paths.php';
use PHPUnit\Framework\TestCase;
final class HTTPAuthIntegrationTest extends TestCase
{
    public function testCleanControllerServesAuthorizedPagesAndHidesMissingResources():void
    {
        getDB()->exec(file_get_contents(dirname(__DIR__).'/config/migrations/036_public_routes.sql'));
        $guest=[];
        self::assertSame(200,$this->request($guest,'login')['status']);
        self::assertSame(404,$this->request($guest,'feed')['status']);
        $cookies=[];$this->login($cookies,false);
        foreach(['feed','perfil','mercado','bandas','salas','mensajes','buscar'] as $route){
            $page=$this->request($cookies,$route);
            self::assertSame(200,$page['status'],$route.': '.substr(strip_tags($page['body']),0,150));
            self::assertStringContainsString('<main',$page['body']);
            self::assertStringContainsString('<base href="http://127.0.0.1:8765/">',$page['body']);
        }
        require_once dirname(__DIR__).'/includes/routing/access.php';
        $id=(new MusicMad\Routing\ResourceAccess(getDB(),$this->uid))->reference('user',$this->uid);
        self::assertSame(200,$this->request($cookies,'musico/'.$id)['status']);
        $missing=$this->request($cookies,'musico/'.str_repeat('f',32));
        $anonymous=$this->request($guest,'musico/'.$id);
        self::assertSame(404,$missing['status']);self::assertSame(404,$anonymous['status']);
        self::assertSame($missing['body'],$anonymous['body']);
        self::assertSame(403,$this->request($cookies,'perfil','POST',['bio'=>'sin token'])['status']);
        foreach(['front.php','ruta-inventada','includes/auth','sala/../config/local.php','sala/%2e%2e/config/local.php','sala/'.str_repeat('f',31),'feed.php.jpg'] as $bad){
            self::assertSame(404,$this->request($cookies,$bad)['status'],$bad);
        }
    }
    private int $uid=0;
    private string $email='';
    private string $REPLACE_WITH_LOCAL_VALUE='MusicMad_HTTP_QA_42!';
    protected function setUp(): void
    {
        if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo servidor QA aislado.');
        self::assertSame('musicmad_qa',DB_NAME);
        $status=@file_get_contents('http://127.0.0.1:8765/api/v1/',false,stream_context_create(['http'=>['timeout'=>2,'ignore_errors'=>true]]));
        if($status===false)$this->markTestSkipped('Arranca tests/router.php en puerto8765.');
        $this->email='http-'.bin2hex(random_bytes(8)).'@musicmad.invalid';
        $st=getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE,rol) VALUES(?,?,?,?,?)');
        $st->execute(['HTTP','QA',$this->email,REPLACE_WITH_LOCAL_VALUE_hash($this->REPLACE_WITH_LOCAL_VALUE,PASSWORD_BCRYPT),'cliente']);$this->uid=(int)getDB()->lastInsertId();
        getDB()->prepare('INSERT INTO musicmad_email_verified(usuario_id,email_hash,verificado_en) VALUES(?,?,UTC_TIMESTAMP())')->execute([$this->uid,hashCorreoCuenta($this->email)]);
        getDB()->prepare('INSERT INTO musicmad_onboarding_required(usuario_id,paso,completado_en) VALUES(?,6,UTC_TIMESTAMP())')->execute([$this->uid]);
    }
    protected function tearDown(): void
    {
        if($this->uid)getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$this->uid]);
    }
    private function request(array &$cookies,string $path,string $method='GET',array $data=[],?string $bearer=null,array $extraHeaders=[]): array
    {
        $headers=array_merge(['Connection: close'],$extraHeaders);
        if($cookies)$headers[]='Cookie: '.implode('; ',array_map(fn($key,$value)=>$key.'='.$value,array_keys($cookies),$cookies));
        if($bearer!==null)$headers[]='Authorization: Bearer '.$bearer;
        $content='';if($method==='POST'){$json=str_starts_with($path,'api/')||in_array($path,['acciones-pendientes.php','chat-live.php','banda-chat.php','push-suscripcion.php','sala-datos.php','onda.php','escucha.php'],true);$headers[]='Content-Type: '.($json?'application/json':'application/x-www-form-urlencoded');$content=$json?json_encode($data):http_build_query($data);}
        $body=file_get_contents('http://127.0.0.1:8765/'.$path,false,stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$content,'timeout'=>10,'ignore_errors'=>true,'follow_location'=>0]]));
        $responseHeaders=$http_response_header??[];$code=0;
        foreach($responseHeaders as $header){if(preg_match('#^HTTP/\S+ (\d+)#',$header,$m))$code=(int)$m[1];if(preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$header,$m)){$cookies[$m[1]]=$m[2];if($m[2]==='deleted'||$m[2]==='')unset($cookies[$m[1]]);}}
        return ['status'=>$code,'body'=>(string)$body,'headers'=>$responseHeaders];
    }
    public function testPublicJpegEndpointAndProfileKeepStoredAvatarValueSeparate(): void
    {
        $name=$this->uid.'_'.bin2hex(random_bytes(12)).'.jpg';$legacy='uploads/avatars/'.$name;
        $directory=musicmadUploadDirectory('avatars');if(!is_dir($directory))mkdir($directory,0700,true);
        $file=$directory.'/'.$name;
        $pixel='/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==';
        file_put_contents($file,base64_decode($pixel,true));
        getDB()->prepare('UPDATE usuarios SET avatar=? WHERE id=?')->execute([$legacy,$this->uid]);
        try {
            $guest=[];$route='media-image.php?kind=avatars&name='.$name;
            $response=$this->request($guest,$route);self::assertSame(200,$response['status']);self::assertSame(file_get_contents($file),$response['body']);
            self::assertStringContainsString('image/jpeg',implode("\n",$response['headers']));
            $head=$this->request($guest,$route,'HEAD');self::assertSame(200,$head['status']);self::assertSame('',$head['body']);
            self::assertSame(404,$this->request($guest,'media-image.php?kind=avatars&name=..%2Fconfig%2Flocal.php')['status']);
            self::assertSame(404,$this->request($guest,'media-image.php?kind=audio&name=valid.wav')['status']);
            self::assertSame(405,$this->request($guest,$route,'POST')['status']);
            $cookies=[];$this->login($cookies,false);$page=$this->request($cookies,'perfil.php');
            self::assertSame(200,$page['status']);self::assertStringContainsString('name="avatar" value="'.$legacy.'"',$page['body']);
            self::assertStringContainsString(e(musicmadPublicImageUrl($legacy)),$page['body']);
            respaldarMedioGuardado('avatars',$name);
            $expected=file_get_contents($file);unlink($file);clearstatcache(true,$file);
            $restored=$this->request($guest,$route);self::assertSame(200,$restored['status']);self::assertSame($expected,$restored['body']);
            self::assertFileExists($file);
            getDB()->prepare('UPDATE usuarios SET avatar=? WHERE id=?')->execute(['img/avatares/musicos/m1.svg',$this->uid]);
            $otherDeviceChange=$this->request($cookies,'perfil.php');self::assertSame(200,$otherDeviceChange['status']);
            self::assertStringNotContainsString(e(musicmadPublicImageUrl($legacy)),$otherDeviceChange['body']);
            unlink($file);self::assertSame(404,$this->request($guest,$route)['status']);self::assertFileDoesNotExist($file);
        } finally { if(is_file($file))unlink($file);$copy=carpetaRespaldoMedios('avatars').'/'.$name;if(is_file($copy))unlink($copy); }
    }
    private function csrf(string $html): string
    {
        self::assertMatchesRegularExpression('/name="csrf" value="([a-f0-9]{64})"/',$html);preg_match('/name="csrf" value="([a-f0-9]{64})"/',$html,$m);return $m[1];
    }
    public function testUnverifiedEmailCannotWriteViaWebOrApiButCanVerifyAndLogout(): void
    {
        $cookies = [];
        $this->login($cookies, false);
        getDB()->prepare('DELETE FROM musicmad_email_verified WHERE usuario_id=?')->execute([$this->uid]);
        $page = $this->request($cookies, 'verificar-correo.php');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Confirma tu correo', $page['body']);
        $csrf = $this->csrf($page['body']);
        $before = (int) getDB()->query('SELECT COUNT(*) FROM publicaciones')->fetchColumn();
        foreach (['social-accion.php', 'comentarios.php', 'subir-archivo.php', 'banda-accion.php',
            'clip-accion.php', 'colab-subir.php', 'mensajes.php', 'chat-live.php', 'acciones-pendientes.php',
            'perfil.php', 'bienvenida-accion.php', 'mercado.php', 'push-suscripcion.php'] as $path) {
            $response = $this->request($cookies, $path, 'POST', ['csrf' => $csrf, 'accion' => 'publicar'], null,
                ['X-Requested-With: MusicMad-PJAX']);
            self::assertSame(403, $response['status'], $path . ': ' . $response['body']);
            self::assertSame('email_verification_required', json_decode($response['body'], true)['error']);
        }
        $login = $this->request($cookies, 'api/v1/login', 'POST', ['email' => $this->email, 'REPLACE_WITH_LOCAL_VALUE' => $this->REPLACE_WITH_LOCAL_VALUE]);
        self::assertSame(200, $login['status']);
        $token = json_decode($login['body'], true)['access_token'];
        $me = $this->request($cookies, 'api/v1/me', 'GET', [], $token);
        self::assertFalse(json_decode($me['body'], true)['email_verified']);
        $post = $this->request($cookies, 'api/v1/posts', 'POST', ['title' => 'Must not publish'], $token);
        self::assertSame(403, $post['status']);
        self::assertSame('email_verification_required', json_decode($post['body'], true)['error']);
        self::assertSame($before, (int) getDB()->query('SELECT COUNT(*) FROM publicaciones')->fetchColumn());
        self::assertSame(200, $this->request($cookies, 'api/v1/logout', 'POST', [], $token)['status']);
        self::assertSame(403, $this->request($cookies, 'verificar-correo.php', 'POST')['status']);
        // Even a session issued before verification sees the new database status.
        marcarCorreoGoogleVerificado($this->uid, $this->email, true);
        $login = $this->request($cookies, 'api/v1/login', 'POST', ['email' => $this->email, 'REPLACE_WITH_LOCAL_VALUE' => $this->REPLACE_WITH_LOCAL_VALUE]);
        $token = json_decode($login['body'], true)['access_token'];
        self::assertSame(201, $this->request($cookies, 'api/v1/posts', 'POST', ['title' => 'Verified QA'], $token)['status']);
    }
    private function login(array &$cookies,bool $remember=true): void
    {
        $form=$this->request($cookies,'login.php');self::assertSame(200,$form['status']);
        $result=$this->request($cookies,'login.php','POST',['csrf'=>$this->csrf($form['body']),'email'=>$this->email,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE,'recordar'=>$remember?'1':'0']);preg_match('/<div class="alert alert-error"[^>]*>(.*?)<\/div>/s',$result['body'],$error);self::assertSame(302,$result['status'],isset($error[1])?trim(strip_tags($error[1])):'Login no redirigió.');
    }
    public function testBrandedErrorHTTPStatusesPrivateCacheJsonAndNoSensitiveOutput(): void
    {
        $guest=[];foreach(['404.php'=>404,'500.php'=>500,'no-such-musicmad-page'=>404] as $path=>$status){$r=$this->request($guest,$path.'?message=FICTITIOUS_SECRET_DO_NOT_RENDER');self::assertSame($status,$r['status']);self::assertStringContainsString('MusicMad',$r['body']);self::assertStringContainsString('no-store',implode("\n",$r['headers']));self::assertStringNotContainsString('FICTITIOUS_SECRET_DO_NOT_RENDER',$r['body']);self::assertStringNotContainsString('style=',$r['body']);}
        foreach(['qa-failure.php','qa-failure.php?modo=fatal'] as $path){$r=$this->request($guest,$path);self::assertSame(500,$r['status']);self::assertStringContainsString('Hagamos una pausa',$r['body']);self::assertStringNotContainsString('FICTITIOUS_SECRET_DO_NOT_RENDER',$r['body']);self::assertStringNotContainsString('INCOMPLETE_PRIVATE_QA_OUTPUT',$r['body']);}
        foreach([['qa-failure.php?modo=json',[]],['qa-failure.php',['X-Requested-With: MusicMad-PJAX']]] as [$path,$headers]){$r=$this->request($guest,$path,'GET',[],null,$headers);self::assertSame(500,$r['status']);$json=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);self::assertFalse($json['ok']);self::assertSame('temporarily_unavailable',$json['error']);self::assertStringNotContainsString('FICTITIOUS_SECRET_DO_NOT_RENDER',$r['body']);}
    }
    public function testFeedbackAndPlaylistInvitationsRejectMissingCsrfAndExposeExplicitState():void
    {
        $cookies=[];$this->login($cookies,false);
        $created=crearPublicacion($this->uid,['tipo'=>'demo','titulo'=>'Feedback HTTP QA']);$pub=(int)$created['id'];getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,?)')->execute([$pub,'http-feedback-qa.wav',1024]);
        $page=$this->request($cookies,'publicacion.php?id='.$pub);$csrf=$this->csrf($page['body']);$pjax=['X-Requested-With: MusicMad-PJAX'];
        self::assertSame(405,$this->request($cookies,'feedback-accion.php')['status']);
        self::assertSame(403,$this->request($cookies,'feedback-accion.php','POST',['id'=>$pub,'activar'=>'1'],null,$pjax)['status']);self::assertFalse(pistaPideFeedback($pub));
        $r=$this->request($cookies,'feedback-accion.php','POST',['csrf'=>$csrf,'id'=>$pub,'activar'=>'1'],null,$pjax);$body=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);self::assertTrue($body['ok']);self::assertTrue(pistaPideFeedback($pub));
        self::assertStringContainsString('Pido feedback',$this->request($cookies,'feed.php?feedback=1')['body']);
        $created=crearPlaylistSocial3($this->uid,'Equipo HTTP QA','Invitación ficticia',false);$list=(int)$created['id'];
        self::assertSame(405,$this->request($cookies,'playlist-equipo-accion.php')['status']);self::assertSame(403,$this->request($cookies,'playlist-equipo-accion.php','POST',['id'=>$list,'accion'=>'invitar','musico'=>2],null,$pjax)['status']);
        $r=$this->request($cookies,'playlist-equipo-accion.php','POST',['csrf'=>$csrf,'id'=>$list,'accion'=>'invitar','musico'=>2],null,$pjax);$body=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);self::assertTrue($body['ok']);self::assertSame('playlist-equipo.php?id='.$list,$body['redirect']);
        self::assertFalse(miembroPlaylist(2,$list));self::assertNull(obtenerPlaylistSocial3($list,2));self::assertStringContainsString('Invitación pendiente',$this->request($cookies,'playlist-equipo.php?id='.$list)['body']);
    }
    public function testDisabledFeatureReturnsPrivateNotFoundForHtmlAndJson():void
    {
        $cookies=[];$this->login($cookies,false);
        foreach([[],['X-Requested-With: MusicMad-PJAX']] as $headers){$r=$this->request($cookies,'qa-flags-off.php','GET',[],null,$headers);self::assertSame(404,$r['status']);self::assertStringContainsString('no-store',implode("\n",$r['headers']));if($headers){$json=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);self::assertFalse($json['ok']);self::assertSame('not_found',$json['error']);}else self::assertStringContainsString('MusicMad',$r['body']);}
    }
    public function testRepostCommentCsrfReplayAndEscapedProfileRendering():void
    {
        $cookies=[];$this->login($cookies,false);$source=crearPublicacion(2,['tipo'=>'texto','titulo'=>'Repost HTTP QA']);$pub=(int)$source['id'];
        try{
            $page=$this->request($cookies,'publicacion.php?id='.$pub);$csrf=$this->csrf($page['body']);$headers=['X-Requested-With: MusicMad-PJAX'];$data=['id'=>$pub,'accion'=>'guardar','texto'=>'<script>no()</script> Buen ritmo','solicitud'=>bin2hex(random_bytes(16))];
            self::assertSame(405,$this->request($cookies,'repost-accion.php')['status']);self::assertSame(403,$this->request($cookies,'repost-accion.php','POST',$data,null,$headers)['status']);$data['csrf']=$csrf;
            foreach([1,2] as $try){$r=$this->request($cookies,'repost-accion.php','POST',$data,null,$headers);$json=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);self::assertTrue($json['ok']);self::assertSame('musico.php?id='.$this->uid,$json['redirect']);}
            $profile=$this->request($cookies,'musico.php?id='.$this->uid);self::assertStringContainsString('&lt;script&gt;no()&lt;/script&gt; Buen ritmo',$profile['body']);self::assertStringNotContainsString('<script>no()</script>',$profile['body']);
            $data['texto']='Cambiado';$r=$this->request($cookies,'repost-accion.php','POST',$data,null,$headers);self::assertFalse(json_decode($r['body'],true,512,JSON_THROW_ON_ERROR)['ok']);
        }finally{getDB()->prepare('DELETE FROM publicaciones WHERE id=?')->execute([$pub]);}
    }
    public function testRequiredTutorialBlocksHtmlFetchAndJWTWithoutSkip(): void
    {
        getDB()->prepare('DELETE FROM musicmad_onboarding_required WHERE usuario_id=?')->execute([$this->uid]);
        $a=[];$form=$this->request($a,'login.php');
        $entered=$this->request($a,'login.php','POST',['csrf'=>$this->csrf($form['body']),'email'=>$this->email,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE,'recordar'=>'0']);
        self::assertSame(302,$entered['status']);self::assertContains('Location: bienvenida.php',$entered['headers']);
        foreach(['feed.php','perfil.php','mensajes.php','musicos.php','bandas.php','mercado.php','salas.php','mapa.php','configuracion.php'] as $path){
            $response=$this->request($a,$path);self::assertSame(302,$response['status'],$path);self::assertContains('Location: bienvenida.php',$response['headers']);
        }
        $page=$this->request($a,'bienvenida.php');self::assertSame(200,$page['status']);self::assertStringNotContainsString('Ahora no',$page['body']);self::assertStringNotContainsString('value="omitir"',$page['body']);
        $csrf=$this->csrf($page['body']);
        $blocked=$this->request($a,'social-accion.php','POST',['csrf'=>$csrf,'accion'=>'publicar'],null,['X-Requested-With: MusicMad-PJAX']);
        self::assertSame(403,$blocked['status']);self::assertSame('onboarding_required',json_decode($blocked['body'],true)['error']);
        self::assertSame(403,$this->request($a,'bienvenida-accion.php','POST',['accion'=>'guardar'])['status']);
        $skip=$this->request($a,'bienvenida-accion.php','POST',['csrf'=>$csrf,'accion'=>'omitir','paso'=>1,'revision'=>0,'solicitud'=>bin2hex(random_bytes(16))],null,['X-Requested-With: MusicMad-PJAX']);
        self::assertFalse(json_decode($skip['body'],true)['ok']);self::assertSame(1,(int)estadoOnboarding($this->uid)['paso']);
        $api=[];$login=$this->request($api,'api/v1/login','POST',['email'=>$this->email,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE]);self::assertSame(200,$login['status']);$token=json_decode($login['body'],true)['access_token'];
        foreach(['feed','posts'] as $resource){$blocked=$this->request($api,'api/v1/'.$resource,$resource==='feed'?'GET':'POST',['title'=>'blocked QA'],$token);self::assertSame(403,$blocked['status']);self::assertSame('onboarding_required',json_decode($blocked['body'],true)['error']);}
        self::assertFalse(json_decode($this->request($api,'api/v1/me','GET',[],$token)['body'],true)['onboarding_complete']);
        self::assertSame(200,$this->request($api,'api/v1/logout','POST',[],$token)['status']);
    }
    public function testArtistDashboardAndListeningReceiptHTTP(): void
    {
        $a=[];$guest=[];self::assertSame(405,$this->request($guest,'escucha.php')['status']);self::assertSame(401,$this->request($guest,'escucha.php','POST',[])['status']);self::assertSame(302,$this->request($guest,'estadisticas.php')['status']);$this->login($a,false);
        getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute(['Other artist','QA','artist_'.bin2hex(random_bytes(6)).'@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_Artist_QA_42!',PASSWORD_BCRYPT)]);$other=(int)getDB()->lastInsertId();
        $make=function(int $owner,string $title): int{getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'demo',?)")->execute([$owner,$title]);$pub=(int)getDB()->lastInsertId();getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) SELECT ?,archivo,bytes FROM publicacion_audio WHERE publicacion_id=9')->execute([$pub]);getDB()->prepare('INSERT INTO publicacion_medios(publicacion_id,sha256,duracion,mime,codec) SELECT ?,sha256,duracion,mime,codec FROM publicacion_medios WHERE publicacion_id=9')->execute([$pub]);return $pub;};
        try{
            $own=$make($this->uid,'Only my HTTP track QA');$pub=$make($other,'Excluded other artist QA');$page=$this->request($a,'estadisticas.php?dias=7&usuario='.$other);self::assertSame(200,$page['status']);self::assertStringContainsString('Only my HTTP track QA',$page['body']);self::assertStringContainsString('no-store',implode("\n",$page['headers']));self::assertStringNotContainsString('Excluded other artist QA',$page['body']);self::assertStringContainsString('chart-4.5.1.umd.min.js',$page['body']);$csrf=$this->csrf($page['body']);self::assertSame(405,$this->request($a,'estadisticas.php','POST',['csrf'=>$csrf])['status']);self::assertSame(200,$this->request($a,'logros.php')['status']);
            self::assertSame(206,$this->request($a,'audio.php?id='.$pub,'GET',[],null,['Range: bytes=0-15'])['status']);self::assertSame(0,(int)getDB()->query('SELECT COUNT(*) FROM reproducciones WHERE publicacion_id='.$pub)->fetchColumn());self::assertSame(0,(int)getDB()->query('SELECT COUNT(*) FROM musicmad_confirmed_plays WHERE publicacion_id='.$pub)->fetchColumn());
            $nonce=bin2hex(random_bytes(16));$input=['accion'=>'iniciar','publicacion'=>$pub,'solicitud'=>$nonce];self::assertSame(403,$this->request($a,'escucha.php','POST',$input)['status']);preg_match('/name="musicmad-account" content="([a-f0-9]{64})"/',$page['body'],$account);self::assertNotEmpty($account[1]);$headers=['X-MusicMad-CSRF: '.$csrf,'X-MusicMad-Account: '.$account[1]];self::assertSame(409,$this->request($a,'escucha.php','POST',$input,null,['X-MusicMad-CSRF: '.$csrf,'X-MusicMad-Account: '.str_repeat('0',64)])['status']);$start=$this->request($a,'escucha.php','POST',$input,null,$headers);self::assertSame(200,$start['status']);self::assertSame(5000,json_decode($start['body'],true)['umbral_ms']);
            $input['accion']='confirmar';$input['escuchados_ms']=5000;$early=json_decode($this->request($a,'escucha.php','POST',$input,null,$headers)['body'],true);self::assertFalse($early['confirmada']);self::assertGreaterThan(0,$early['esperar_ms']);getDB()->prepare('UPDATE musicmad_listening_receipts SET emitida_en=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 8 SECOND) WHERE usuario_id=? AND solicitud=?')->execute([$this->uid,$nonce]);
            $done=json_decode($this->request($a,'escucha.php','POST',$input+['usuario'=>$other],null,$headers)['body'],true);self::assertTrue($done['confirmada']);self::assertTrue($done['nueva']);self::assertFalse(json_decode($this->request($a,'escucha.php','POST',$input,null,$headers)['body'],true)['nueva']);self::assertSame(1,datosEstadisticasArtista($other)['resumen']['escuchas']);self::assertStringContainsString('no-store',implode("\n",$start['headers']));
            getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$other]);self::assertSame(404,$this->request($a,'escucha.php','POST',$input,null,$headers)['status']);getDB()->prepare('UPDATE usuarios SET activo=0 WHERE id=?')->execute([$this->uid]);self::assertSame(401,$this->request($a,'escucha.php','POST',$input,null,$headers)['status']);
        }finally{getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$other]);}
    }
    private function multipart(array $cookies,array $fields,string $bytes): array
    {
        $boundary='MusicMadQA'.bin2hex(random_bytes(12));$content='';
        foreach($fields as $key=>$value)$content.='--'.$boundary."\r\n".'Content-Disposition: form-data; name="'.$key.'"'."\r\n\r\n".$value."\r\n";
        $content.='--'.$boundary."\r\n".'Content-Disposition: form-data; name="audio"; filename="native-qa.wav"'."\r\nContent-Type: audio/wav\r\n\r\n".$bytes."\r\n--".$boundary."--\r\n";
        $header=['Connection: close','X-Requested-With: MusicMad-PJAX','Content-Type: multipart/form-data; boundary='.$boundary,'Cookie: '.implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($cookies),$cookies))];
        $body=file_get_contents('http://127.0.0.1:8765/colab-subir.php',false,stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$header),'content'=>$content,'timeout'=>15,'ignore_errors'=>true,'follow_location'=>0]]));$code=0;foreach($http_response_header??[] as $h)if(preg_match('#^HTTP/\\S+ (\\d+)#',$h,$m))$code=(int)$m[1];
        return ['status'=>$code,'body'=>(string)$body];
    }
    public function testEndorsementsHTTPScopesCsrfEscapingAndRetirement(): void
    {
        $a=[];$guest=[];self::assertSame(302,$this->request($guest,'recomendaciones.php')['status']);self::assertSame(405,$this->request($guest,'recomendacion-accion.php')['status']);self::assertSame(401,$this->request($guest,'recomendacion-accion.php','POST',[],null,['X-Requested-With: MusicMad-PJAX'])['status']);$this->login($a,false);
        getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute(['Voces','Músico QA','voice-http-'.bin2hex(random_bytes(8)).'@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_Endorsement_QA_42!',PASSWORD_BCRYPT)]);$target=(int)getDB()->lastInsertId();
        try{
            $page=$this->request($a,'recomendaciones.php?usuario='.$target);self::assertSame(200,$page['status']);self::assertStringContainsString('no-store',implode("\n",$page['headers']));$csrf=$this->csrf($page['body']);$data=['accion'=>'guardar','usuario'=>$target,'texto'=>'<script>QA</script> Excelente trabajo escuchando al grupo.','solicitud'=>bin2hex(random_bytes(16))];self::assertSame(403,$this->request($a,'recomendacion-accion.php','POST',$data)['status']);$data['csrf']=$csrf;
            $sent=$this->request($a,'recomendacion-accion.php','POST',$data,null,['X-Requested-With: MusicMad-PJAX']);self::assertTrue(json_decode($sent['body'],true)['ok'],$sent['body']);self::assertTrue(json_decode($this->request($a,'recomendacion-accion.php','POST',$data,null,['X-Requested-With: MusicMad-PJAX'])['body'],true)['ok']);self::assertCount(1,recomendacionesMusico($this->uid,$target));$view=$this->request($a,'recomendaciones.php?usuario='.$target);self::assertStringContainsString('&lt;script&gt;QA&lt;/script&gt;',$view['body']);self::assertStringNotContainsString('<script>QA</script>',$view['body']);self::assertSame(405,$this->request($a,'recomendaciones.php','POST')['status']);
            accionRecomendacion($target,$this->uid,'ocultar','',bin2hex(random_bytes(16)));self::assertSame([],recomendacionesMusico($this->uid,$target));$hidden=$this->request($a,'recomendaciones.php?usuario='.$target.'&ver=ocultas');self::assertStringNotContainsString('<blockquote>&lt;script&gt;QA&lt;/script&gt;',$hidden['body']);$own=$this->request($a,'recomendaciones.php?ver=enviadas');self::assertStringContainsString('&lt;script&gt;QA&lt;/script&gt;',$own['body']);
            getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(?,?,'bloquear')")->execute([$target,$this->uid]);self::assertSame(404,$this->request($a,'recomendaciones.php?usuario='.$target)['status']);$retire=['csrf'=>$csrf,'accion'=>'retirar','usuario'=>$target,'origen'=>'enviadas','solicitud'=>bin2hex(random_bytes(16))];$r=$this->request($a,'recomendacion-accion.php','POST',$retire,null,['X-Requested-With: MusicMad-PJAX']);$result=json_decode($r['body'],true);self::assertTrue($result['ok']);self::assertSame('recomendaciones.php?usuario='.$this->uid.'&ver=enviadas',$result['redirect']);self::assertSame([],recomendacionesEscritas($this->uid));
        }finally{getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$target]);}
    }
    public function testChallengesHTTPAuthenticationCsrfParticipationAndVoting(): void
    {
        $guest=[];$a=[];self::assertSame(302,$this->request($guest,'retos.php')['status']);self::assertSame(405,$this->request($guest,'reto-accion.php')['status']);$this->login($a,false);
        getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE,rol) VALUES(?,?,?,?,?)')->execute(['Retos','Equipo QA','challenge-http-'.bin2hex(random_bytes(8)).'@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_Challenge_QA_42!',PASSWORD_BCRYPT),'administrador']);$staff=(int)getDB()->lastInsertId();
        try{
            $id=crearReto($staff,['titulo'=>'Reto HTTP QA','inicio'=>gmdate('Y-m-d\TH:i',time()+3600),'votacion'=>gmdate('Y-m-d\TH:i',time()+86400),'fin'=>gmdate('Y-m-d\TH:i',time()+172800)],bin2hex(random_bytes(16)));getDB()->prepare('UPDATE retos_musicales SET inicio=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id=?')->execute([$id]);
            $page=$this->request($a,'reto.php?id='.$id);self::assertSame(200,$page['status']);self::assertStringContainsString('CSS/challenges.css?v=',$page['body']);self::assertStringContainsString('no-store',implode("\n",$page['headers']));$csrf=$this->csrf($page['body']);self::assertSame(405,$this->request($a,'retos.php','POST')['status']);
            getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'demo','Challenge HTTP own QA')")->execute([$this->uid]);$pub=(int)getDB()->lastInsertId();getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) SELECT ?,archivo,bytes FROM publicacion_audio WHERE publicacion_id=9')->execute([$pub]);getDB()->prepare('INSERT INTO publicacion_medios(publicacion_id,sha256,duracion,mime,codec) SELECT ?,sha256,duracion,mime,codec FROM publicacion_medios WHERE publicacion_id=9')->execute([$pub]);
            $data=['accion'=>'participar','id'=>$id,'pista'=>$pub,'solicitud'=>bin2hex(random_bytes(16))];self::assertSame(403,$this->request($a,'reto-accion.php','POST',$data)['status']);$data['csrf']=$csrf;$sent=$this->request($a,'reto-accion.php','POST',$data,null,['X-Requested-With: MusicMad-PJAX']);$result=json_decode($sent['body'],true,512,JSON_THROW_ON_ERROR);self::assertTrue($result['ok'],$sent['body']);self::assertSame('reto.php?id='.$id,$result['redirect']);self::assertTrue(json_decode($this->request($a,'reto-accion.php','POST',$data,null,['X-Requested-With: MusicMad-PJAX'])['body'],true)['ok']);self::assertCount(1,rankingReto($this->uid,$id));self::assertStringContainsString('data-track-play',$this->request($a,'reto.php?id='.$id)['body']);
            $denied=$this->request($a,'reto-accion.php','POST',['csrf'=>$csrf,'accion'=>'cancelar','id'=>$id],null,['X-Requested-With: MusicMad-PJAX']);self::assertFalse(json_decode($denied['body'],true)['ok']);self::assertSame('participacion',retoPara($this->uid,$id)['fase']);
            getDB()->prepare('UPDATE musicmad_challenge_details SET votacion_desde=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE reto_id=?')->execute([$id]);$own=$this->request($a,'reto-accion.php','POST',['csrf'=>$csrf,'accion'=>'votar','id'=>$id,'participante'=>$this->uid,'solicitud'=>bin2hex(random_bytes(16))],null,['X-Requested-With: MusicMad-PJAX']);self::assertFalse(json_decode($own['body'],true)['ok']);self::assertSame(0,miVotoReto($this->uid,$id));
            getDB()->prepare('UPDATE usuarios SET activo=0 WHERE id=?')->execute([$this->uid]);self::assertSame(401,$this->request($a,'reto-accion.php','POST',$data,null,['X-Requested-With: MusicMad-PJAX'])['status']);
        }finally{getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$staff]);}
    }
    public function testStudioNativeUploadChecksCsrfAndReplaysOneStem(): void
    {
        $a=[];$guest=[];self::assertSame(405,$this->request($guest,'colab-subir.php')['status']);self::assertSame(401,$this->request($guest,'colab-subir.php','POST',[],null,['X-Requested-With: MusicMad-PJAX'])['status']);$this->login($a,false);getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'demo','Base HTTP native QA')")->execute([$this->uid]);$base=(int)getDB()->lastInsertId();getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) SELECT ?,archivo,bytes FROM publicacion_audio WHERE publicacion_id=9')->execute([$base]);getDB()->prepare('INSERT INTO publicacion_medios(publicacion_id,sha256,duracion,mime,codec) SELECT ?,sha256,duracion,mime,codec FROM publicacion_medios WHERE publicacion_id=9')->execute([$base]);$project=crearColaboracion($this->uid,['titulo'=>'Native studio QA','pista'=>$base]);$page=$this->request($a,'colab.php?id='.$project);self::assertStringContainsString('js/upload-progress.js?v=',$page['body']);self::assertStringContainsString('data-studio-recorder',$page['body']);
        $bytes=file_get_contents(musicmadQaRoot().'/uploads/audio/qa-tone.wav');$fields=['colaboracion_id'=>$project,'solicitud'=>bin2hex(random_bytes(16)),'titulo'=>'Native take QA','tipo'=>'demo','offset_ms'=>30,'volumen'=>55];$created=[];$pending=[];
        try{
            $denied=$this->multipart($a,$fields,$bytes);self::assertSame(403,$denied['status'],$denied['body']);$fields['csrf']=$this->csrf($page['body']);
            $r=$this->multipart($a,$fields,$bytes);self::assertSame(200,$r['status']);$data=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);self::assertTrue($data['ok'],$r['body']);self::assertStringStartsWith('colab.php?id='.$project.'#stem-',$data['redirect']);$stems=stemsColabPara($this->uid,$project);self::assertCount(1,$stems);$pub=(int)$stems[0]['publicacion_id'];$st=getDB()->prepare('SELECT archivo FROM publicacion_audio WHERE publicacion_id=?');$st->execute([$pub]);$created[]=$st->fetchColumn();$pending[]=$fields['solicitud'];
            self::assertSame(hash('sha256',$bytes),hash_file('sha256',AUDIO_DIR.'/'.$created[0]));$again=json_decode($this->multipart($a,$fields,$bytes)['body'],true);self::assertTrue($again['ok']);self::assertSame($data['redirect'],$again['redirect']);self::assertCount(1,stemsColabPara($this->uid,$project));
            $changed=$bytes;$changed[strlen($changed)-1]=chr(ord($changed[strlen($changed)-1])^1);self::assertFalse(json_decode($this->multipart($a,$fields,$changed)['body'],true)['ok']);self::assertSame(hash('sha256',$bytes),hash_file('sha256',AUDIO_DIR.'/'.$created[0]));
            cambiarEstadoColab($this->uid,$project,'cerrada');$fields['solicitud']=bin2hex(random_bytes(16));$pending[]=$fields['solicitud'];$before=(int)getDB()->query('SELECT COUNT(*) FROM publicaciones')->fetchColumn();self::assertFalse(json_decode($this->multipart($a,$fields,$bytes)['body'],true)['ok']);self::assertSame($before,(int)getDB()->query('SELECT COUNT(*) FROM publicaciones')->fetchColumn());
        }finally{foreach($created as $file){self::assertMatchesRegularExpression('/^[a-f0-9]+[.]wav$/D',$file);if(is_file(AUDIO_DIR.'/'.$file))unlink(AUDIO_DIR.'/'.$file);}foreach($pending as $id){$path=rutaSubidaPendiente($id);if(is_file($path))unlink($path);}}
    }
    public function testCreativeProjectsAndEphemeralClipMediaHTTP(): void
    {
        $a=[];$guest=[];$this->login($a,false);getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'demo','Fragmento HTTP QA')")->execute([$this->uid]);$pub=(int)getDB()->lastInsertId();getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) SELECT ?,archivo,bytes FROM publicacion_audio WHERE publicacion_id=9')->execute([$pub]);getDB()->prepare('INSERT INTO publicacion_medios(publicacion_id,sha256,duracion,mime,ancho,alto,codec) SELECT ?,sha256,duracion,mime,ancho,alto,codec FROM publicacion_medios WHERE publicacion_id=9')->execute([$pub]);
        $page=$this->request($a,'clips.php');self::assertSame(200,$page['status']);self::assertStringContainsString('for="clips-source"',$page['body']);self::assertStringContainsString('id="clips-source"',$page['body']);$csrf=$this->csrf($page['body']);$clip=crearClipMusical($this->uid,['pista'=>$pub,'inicio'=>2.345,'duracion'=>5],bin2hex(random_bytes(16)));
        self::assertSame(401,$this->request($guest,'clip-medio.php?id='.$clip)['status']);self::assertSame(405,$this->request($a,'clip-medio.php?id='.$clip,'POST')['status']);$view=$this->request($a,'clip.php?id='.$clip);self::assertSame(200,$view['status']);self::assertStringContainsString('data-clip-start="2345"',$view['body']);$media=$this->request($a,'clip-medio.php?id='.$clip,'GET',[],null,['Range: bytes=0-15']);self::assertSame(206,$media['status']);self::assertSame(16,strlen($media['body']));self::assertStringContainsString('no-store',implode("\n",$media['headers']));
        self::assertSame(403,$this->request($a,'clip-accion.php','POST',['accion'=>'retirar','id'=>$clip])['status']);self::assertSame(303,$this->request($a,'clip-accion.php','POST',['csrf'=>$csrf,'accion'=>'retirar','id'=>$clip])['status']);self::assertSame(404,$this->request($a,'clip-medio.php?id='.$clip)['status']);self::assertSame(404,$this->request($a,'clip.php?id='.$clip)['status']);self::assertSame(206,$this->request($a,'audio.php?id='.$pub,'GET',[],null,['Range: bytes=0-15'])['status']);
        $pjax=$this->request($a,'clip-accion.php','POST',['csrf'=>$csrf,'accion'=>'crear','pista'=>$pub,'inicio'=>1,'duracion'=>4,'solicitud'=>bin2hex(random_bytes(16))],null,['X-Requested-With: MusicMad-PJAX']);self::assertSame(200,$pjax['status']);$body=json_decode($pjax['body'],true,512,JSON_THROW_ON_ERROR);self::assertTrue($body['ok']);self::assertMatchesRegularExpression('/^clip[.]php[?]id=[1-9][0-9]*$/',$body['redirect']);
        $project=crearColaboracion($this->uid,['titulo'=>'Proyecto HTTP QA','pista'=>$pub]);self::assertSame(200,$this->request($a,'colab.php?id='.$project)['status']);self::assertSame(403,$this->request($a,'colab-accion.php','POST',['accion'=>'estado','id'=>$project,'estado'=>'cerrada'])['status']);self::assertSame(303,$this->request($a,'colab-accion.php','POST',['csrf'=>$csrf,'accion'=>'estado','id'=>$project,'estado'=>'cerrada'])['status']);self::assertSame('cerrada',colabDisponiblePara($this->uid,$project)['estado']);$changed=$this->request($a,'colab-accion.php','POST',['csrf'=>$csrf,'accion'=>'estado','id'=>$project,'estado'=>'abierta'],null,['X-Requested-With: MusicMad-PJAX']);self::assertSame('colab.php?id='.$project,json_decode($changed['body'],true)['redirect']);
    }
    public function testWaveformAndPreciseMomentsHTTP(): void
    {
        $a=[];$guest=[];self::assertSame(401,$this->request($guest,'onda.php?id=9')['status']);$this->login($a,false);$page=$this->request($a,'publicacion.php?id=9');$csrf=$this->csrf($page['body']);self::assertSame(200,$page['status']);self::assertStringContainsString('data-waveform="9"',$page['body']);self::assertSame(200,$this->request($a,'onda.php?id=9')['status']);
        self::assertSame(403,$this->request($a,'onda.php','POST',['id'=>9,'picos'=>array_fill(0,512,1),'duracion'=>60])['status']);$header=['X-MusicMad-CSRF: '.$csrf];$hash=getDB()->query('SELECT sha256 FROM publicacion_medios WHERE publicacion_id=9')->fetchColumn();self::assertSame(404,$this->request($a,'onda.php','POST',['id'=>9,'picos'=>array_fill(0,512,1),'duracion'=>60,'sha256'=>$hash],null,$header)['status']);
        $sent=$this->request($a,'social-accion.php','POST',['csrf'=>$csrf,'accion'=>'comentario_tiempo','id'=>9,'segundo'=>'12.345','texto'=>'Momento preciso HTTP QA','volver'=>'publicacion.php?id=9']);self::assertSame(302,$sent['status']);$comments=comentariosTiempoDe([9],$this->uid);$own=array_values(array_filter($comments[9]??[],fn($row)=>(int)$row['usuario_id']===$this->uid));self::assertCount(1,$own);self::assertSame(12.345,(float)$own[0]['segundo']);
    }
    public function testListeningRoomHTTPGatesAndRevisionControls(): void
    {
        $room=crearSalaEscucha(1,['nombre'=>'Sala HTTP QA','modo'=>'enlace','publicacion'=>9]);$guest=[];$a=[];
        try{
            self::assertSame(401,$this->request($guest,'sala-datos.php?id='.$room)['status']);$this->login($a,false);self::assertSame(404,$this->request($a,'sala.php?id='.$room)['status']);self::assertSame(404,$this->request($a,'sala-datos.php?id='.$room)['status']);
            $code=salaDisponiblePara(1,$room)['codigo'];$page=$this->request($a,'sala.php?id='.$room.'&codigo='.$code);self::assertSame(200,$page['status']);$csrf=$this->csrf($page['body']);
            self::assertSame(403,$this->request($a,'sala-accion.php','POST',['accion'=>'entrar','id'=>$room,'codigo'=>$code])['status']);
            self::assertSame(303,$this->request($a,'sala-accion.php','POST',['csrf'=>$csrf,'accion'=>'entrar','id'=>$room,'codigo'=>$code])['status']);$state=$this->request($a,'sala-datos.php?id='.$room);self::assertSame(200,$state['status']);self::assertStringContainsString('no-store',implode("\n",$state['headers']));self::assertStringNotContainsString($code,$state['body']);self::assertStringNotContainsString($this->email,$state['body']);
            self::assertSame(403,$this->request($a,'sala-datos.php','POST',['id'=>$room,'accion'=>'mensaje','texto'=>'Hola','solicitud'=>bin2hex(random_bytes(16))])['status']);
            $headers=['X-MusicMad-CSRF: '.$csrf];$nonce=bin2hex(random_bytes(16));$data=['id'=>$room,'accion'=>'mensaje','texto'=>'Escuchando desde QA','solicitud'=>$nonce];
            $sent=$this->request($a,'sala-datos.php','POST',$data,null,$headers);self::assertSame(200,$sent['status']);self::assertSame(200,$this->request($a,'sala-datos.php','POST',$data,null,$headers)['status']);self::assertSame(409,$this->request($a,'sala-datos.php','POST',array_replace($data,['texto'=>'Otro mensaje']),null,$headers)['status']);
            self::assertSame(404,$this->request($a,'sala-datos.php','POST',['id'=>$room,'accion'=>'control','revision'=>1,'posicion'=>0,'reproduccion'=>'reproduciendo'],null,$headers)['status']);
            cerrarOSalirSala(1,$room,true);self::assertSame(404,$this->request($a,'sala-datos.php?id='.$room)['status']);
        }finally{getDB()->prepare('DELETE FROM salas_escucha WHERE id=?')->execute([$room]);}
    }
    public function testEmbedRequiresSeparateConsentAndNeverUsesOwnerPreview(): void
    {
        $slug='qa_'.bin2hex(random_bytes(6));guardarPortafolio($this->uid,['slug'=>$slug,'habilitado'=>'1','color'=>'verde']);$guest=[];self::assertSame(404,$this->request($guest,'embed.php?usuario='.$slug)['status']);
        guardarPortafolio($this->uid,['slug'=>$slug,'habilitado'=>'1','incrustable'=>'1','color'=>'verde']);$open=$this->request($guest,'embed.php?usuario='.$slug);self::assertSame(200,$open['status']);self::assertStringContainsString('frame-ancestors *',implode("\n",$open['headers']));self::assertStringNotContainsString('name="csrf"',$open['body']);self::assertStringNotContainsString('<script',$open['body']);self::assertStringNotContainsString($this->email,$open['body']);self::assertStringNotContainsString('X-Frame-Options',implode("\n",$open['headers']));
        $a=[];$this->login($a,false);getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$this->uid]);self::assertSame(404,$this->request($a,'embed.php?usuario='.$slug.'&vista=1')['status']);self::assertSame(404,$this->request($guest,'embed.php?usuario='.$slug)['status']);
    }
    public function testPublicPortfolioPrettyURLMetadataAndAuthorizedRangeHTTP(): void
    {
        $slug='qa_'.bin2hex(random_bytes(6));$a=[];$this->login($a,false);$form=$this->request($a,'epk.php');self::assertSame(200,$form['status']);$dom=new DOMDocument();@$dom->loadHTML($form['body'],LIBXML_NONET);$xpath=new DOMXPath($dom);self::assertSame(2,$xpath->query('//details[contains(@class,"epk-options")]/summary')->length);self::assertSame(5,$xpath->query('//details[contains(@class,"epk-options")][2]//select')->length);
        $st=getDB()->prepare('SELECT archivo FROM publicacion_audio WHERE publicacion_id=1');$st->execute();$file=$st->fetchColumn();self::assertNotFalse($file);getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'cancion','EPK HTTP propia QA')")->execute([$this->uid]);$id=(int)getDB()->lastInsertId();getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,44)')->execute([$id,$file]);
        $input=['csrf'=>$this->csrf($form['body']),'slug'=>$slug,'habilitado'=>'1','color'=>'azul','destacadas'=>[$id],'youtube'=>'https://youtu.be/qa'];
        self::assertSame(403,$this->request($a,'epk.php','POST',array_diff_key($input,['csrf'=>1]))['status']);$saved=$this->request($a,'epk.php','POST',$input);self::assertSame(200,$saved['status']);self::assertStringContainsString('Portafolio guardado',$saved['body']);
        $guest=[];$canonical=$this->request($guest,'@'.$slug.'/');self::assertSame(308,$canonical['status']);self::assertStringContainsString('/@'.$slug,implode("\n",$canonical['headers']));$public=$this->request($guest,'@'.$slug.'?usuario=otra_cuenta');self::assertSame(200,$public['status']);self::assertStringContainsString('property="og:title"',$public['body']);self::assertStringContainsString('EPK HTTP propia QA',$public['body']);self::assertStringNotContainsString($this->email,$public['body']);self::assertStringContainsString('pista-publica.php?id='.$id,$public['body']);
        $range=$this->request($guest,'pista-publica.php?id='.$id,'GET',[],null,['Range: bytes=0-15']);self::assertSame(206,$range['status']);self::assertSame(16,strlen($range['body']));self::assertStringContainsString('Accept-Ranges: bytes',implode("\n",$range['headers']));getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'cancion','HTTP no destacada QA')")->execute([$this->uid]);$notFeatured=(int)getDB()->lastInsertId();getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,44)')->execute([$notFeatured,$file]);self::assertSame(404,$this->request($guest,'pista-publica.php?id='.$notFeatured)['status']);self::assertSame(405,$this->request($guest,'pista-publica.php?id='.$id,'POST')['status']);
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$this->uid]);$hidden=$this->request($guest,'@'.$slug);self::assertSame(404,$hidden['status']);self::assertStringNotContainsString('EPK HTTP propia QA',$hidden['body']);self::assertStringNotContainsString('property="og:title"',$hidden['body']);self::assertSame(404,$this->request($guest,'pista-publica.php?id='.$id)['status']);self::assertSame(200,$this->request($a,'portafolio.php?usuario='.$slug.'&vista=1')['status']);
    }
    public function testTwoFactorQRIsPrivateAndContainsStandardLocalURI(): void
    {
        $a=[];$this->login($a,false);$page=$this->request($a,'seguridad.php');self::assertSame(200,$page['status']);$prepared=$this->request($a,'seguridad.php','POST',['csrf'=>$this->csrf($page['body']),'accion'=>'preparar','REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE]);self::assertSame(200,$prepared['status']);self::assertStringContainsString('data-qr-kind="totp"',$prepared['body']);self::assertStringContainsString('assets/vendor/qrcode/qrcodegen-1.8.0.js',$prepared['body']);
        preg_match('/data-qr-value="([^"]+)"/',$prepared['body'],$match);self::assertNotEmpty($match[1]);$uri=html_entity_decode($match[1],ENT_QUOTES,'UTF-8');self::assertStringStartsWith('otpauth://totp/MusicMad',$uri);parse_str(parse_url($uri,PHP_URL_QUERY),$query);self::assertSame('MusicMad',$query['issuer']);self::assertSame('6',$query['digits']);self::assertSame('30',$query['period']);self::assertMatchesRegularExpression('/^[A-Z2-7]+$/',$query['secret']);self::assertStringContainsString('no-store',implode("\n",$prepared['headers']));$guest=[];self::assertSame(302,$this->request($guest,'seguridad.php')['status']);
    }
    public function testPublicQrUrlsOpenWithoutSessionAndNeverExposePrivateContent(): void
    {
        $slug='publicqr_' . bin2hex(random_bytes(8));
        getDB()->prepare('INSERT INTO perfiles_publicos(usuario_id,slug) VALUES(?,?)')->execute([$this->uid,$slug]);
        getDB()->prepare("INSERT INTO musicmad_epk_options(usuario_id,habilitado,color) VALUES(?,1,'violeta')")->execute([$this->uid]);
        $cookies=[];$this->login($cookies,false);
        foreach(['perfil.php','musico.php?id='.$this->uid] as $path){
            $page=$this->request($cookies,$path);self::assertSame(200,$page['status']);
            preg_match('/data-qr-kind="perfil" data-qr-value="([^"]+)"/',$page['body'],$match);self::assertNotEmpty($match);
            $url=html_entity_decode($match[1],ENT_QUOTES,'UTF-8');self::assertStringStartsWith('https://127.0.0.1:8765/portafolio.php?usuario=',$url);
            $guest=[];$opened=$this->request($guest,ltrim(parse_url($url,PHP_URL_PATH),'/').'?'.parse_url($url,PHP_URL_QUERY));
            self::assertSame(200,$opened['status']);self::assertStringContainsString('@'.$slug,$opened['body']);self::assertStringNotContainsString($this->email,$opened['body']);
        }
        getDB()->prepare('INSERT INTO bandas(creador_id,nombre,descripcion,genero,ciudad) VALUES(?,?,?,?,?)')->execute([$this->uid,'QR Banda QA','Descripción pública QA','Jazz','Guadalajara']);$band=(int)getDB()->lastInsertId();
        getDB()->prepare("INSERT INTO eventos_musicales(creador_id,titulo,descripcion,ciudad,inicio,estado) VALUES(?,?,?, ?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY),'publicado')")->execute([$this->uid,'QR Evento QA','Descripción del evento QA','Guadalajara']);$event=(int)getDB()->lastInsertId();
        getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'demo','QR Pista QA')")->execute([$this->uid]);$pub=(int)getDB()->lastInsertId();
        getDB()->prepare('INSERT INTO salas_escucha(anfitrion_id,publicacion_id,nombre,codigo) VALUES(?,?,?,?)')->execute([$this->uid,$pub,'QR Sala QA',bin2hex(random_bytes(16))]);$room=(int)getDB()->lastInsertId();
        getDB()->prepare("INSERT INTO musicmad_room_state(sala_id,modo,actualizada_utc,expira_en) VALUES(?,'publica',UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))")->execute([$room]);
        foreach(['banda'=>$band,'evento'=>$event,'sala'=>$room] as $kind=>$id){
            $url=enlaceCompartiblePublico($kind,$id);self::assertNotNull($url);self::assertStringStartsWith('https://',$url);
            $guest=[];$opened=$this->request($guest,ltrim(parse_url($url,PHP_URL_PATH),'/').'?'.parse_url($url,PHP_URL_QUERY));
            self::assertSame(200,$opened['status']);self::assertStringContainsString('data-qr-kind="'.$kind.'"',$opened['body']);
            self::assertStringNotContainsString($this->email,$opened['body']);self::assertStringNotContainsString('data-room-messages',$opened['body']);
        }
        getDB()->prepare("UPDATE musicmad_room_state SET modo='enlace' WHERE sala_id=?")->execute([$room]);
        self::assertNull(enlaceCompartiblePublico('sala',$room));$guest=[];
        $hidden=$this->request($guest,'vista-publica.php?tipo=sala&id='.$room);$missing=$this->request($guest,'vista-publica.php?tipo=sala&id=4294967295');
        self::assertSame(404,$hidden['status']);self::assertSame(404,$missing['status']);self::assertStringNotContainsString('QR Sala QA',$hidden['body']);
        self::assertSame($hidden['body'],$missing['body']);
        getDB()->prepare("UPDATE musicmad_band_settings SET ingreso='invitacion' WHERE banda_id=?")->execute([$band]);
        // Insert settings if the synthetic band had no settings row.
        getDB()->prepare("INSERT INTO musicmad_band_settings(banda_id,ingreso) VALUES(?,'invitacion') ON DUPLICATE KEY UPDATE ingreso='invitacion'")->execute([$band]);
        self::assertNull(enlaceCompartiblePublico('banda',$band));
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$this->uid]);
        self::assertNull(enlaceCompartiblePublico('perfil',$this->uid));self::assertNull(enlaceCompartiblePublico('evento',$event));
        self::assertSame(404,$this->request($guest,'portafolio.php?usuario='.$slug)['status']);
    }
    public function testDeepLinkSurvivesLoginAndTutorialWithoutExternalNext(): void
    {
        getDB()->prepare('DELETE FROM musicmad_onboarding_required WHERE usuario_id=?')->execute([$this->uid]);
        $cookies=[];
        self::assertSame(302,$this->request($cookies,'banda.php?id=123&next=https%3A%2F%2Fevil.invalid')['status']);
        $form=$this->request($cookies,'login.php');
        $entered=$this->request($cookies,'login.php','POST',['csrf'=>$this->csrf($form['body']),'email'=>$this->email,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE,'recordar'=>'0']);
        self::assertContains('Location: bienvenida.php',$entered['headers']);
        // Synthetic completion: the real five-step path is tested separately.
        getDB()->prepare('INSERT INTO musicmad_onboarding_required(usuario_id,paso,completado_en) VALUES(?,6,UTC_TIMESTAMP())')->execute([$this->uid]);
        $returned=$this->request($cookies,'verificar-correo.php');
        self::assertSame(302,$returned['status']);self::assertContains('Location: banda.php?id=123',$returned['headers']);
        self::assertStringNotContainsString('evil.invalid',implode("\n",$returned['headers']));
    }
    public function testOwnDataExportAndRecoverableDeletionHTTP(): void
    {
        $a=[];$this->login($a);$page=$this->request($a,'datos.php');self::assertSame(200,$page['status']);$csrf=$this->csrf($page['body']);
        self::assertSame(405,$this->request($a,'exportar-datos.php')['status']);self::assertSame(403,$this->request($a,'exportar-datos.php','POST',['REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE])['status']);
        $wrong=$this->request($a,'exportar-datos.php','POST',['csrf'=>$csrf,'REPLACE_WITH_LOCAL_VALUE'=>'incorrecta']);self::assertSame(303,$wrong['status']);
        $export=$this->request($a,'exportar-datos.php','POST',['csrf'=>$csrf,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE,'usuario'=>1]);self::assertSame(200,$export['status']);$data=json_decode($export['body'],true,512,JSON_THROW_ON_ERROR);self::assertSame($this->email,$data['colecciones']['usuarios'][0]['email']);self::assertStringNotContainsString('"REPLACE_WITH_LOCAL_VALUE":',$export['body']);self::assertStringContainsString('attachment; filename=',implode("\n",$export['headers']));self::assertStringContainsString('no-store',implode("\n",$export['headers']));
        self::assertSame(1,(int)getDB()->query('SELECT activo FROM usuarios WHERE id='.$this->uid)->fetchColumn());$bad=$this->request($a,'eliminar-cuenta.php','POST',['csrf'=>$csrf,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE,'confirmacion'=>'ELIMINAR']);self::assertSame(303,$bad['status']);self::assertSame(1,(int)getDB()->query('SELECT activo FROM usuarios WHERE id='.$this->uid)->fetchColumn());
        $done=$this->request($a,'eliminar-cuenta.php','POST',['csrf'=>$csrf,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE,'confirmacion'=>'ELIMINAR','entiendo'=>'1']);self::assertSame(303,$done['status']);self::assertSame(0,(int)getDB()->query('SELECT activo FROM usuarios WHERE id='.$this->uid)->fetchColumn());self::assertSame(302,$this->request($a,'feed.php')['status']);
        $recover=$this->request($a,'reactivar-cuenta.php');self::assertSame(200,$recover['status']);self::assertSame(403,$this->request($a,'reactivar-cuenta.php','POST',['email'=>$this->email,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE])['status']);
        $restored=$this->request($a,'reactivar-cuenta.php','POST',['csrf'=>$this->csrf($recover['body']),'email'=>$this->email,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE]);self::assertSame(200,$restored['status']);self::assertStringContainsString('Eliminación cancelada',$restored['body']);self::assertSame(1,(int)getDB()->query('SELECT activo FROM usuarios WHERE id='.$this->uid)->fetchColumn());self::assertSame(302,$this->request($a,'feed.php')['status']);$this->login($a,false);self::assertSame(200,$this->request($a,'feed.php')['status']);
    }
    public function testModeratorHasReportAccessButCannotManageRolesHTTP(): void
    {
        asignarRolSitio(3,$this->uid,'moderador','MusicMad_QA_Example_42!','');$a=[];$this->login($a,false);self::assertSame(200,$this->request($a,'moderacion.php')['status']);self::assertSame(200,$this->request($a,'mercado-reportes.php')['status']);self::assertSame(302,$this->request($a,'permisos.php')['status']);
        asignarRolSitio(3,$this->uid,'usuario','MusicMad_QA_Example_42!','');self::assertSame(302,$this->request($a,'moderacion.php')['status']);$this->login($a,false);self::assertSame(403,$this->request($a,'moderacion.php')['status']);
    }
    public function testEventPosterCalendarAndPrivateRemindersHTTP(): void
    {
        $a=[];$this->login($a,false);$result=guardarEventoWorkspace($this->uid,['titulo'=>'Cartel HTTP QA','descripcion'=>'Descripción ficticia','ciudad'=>'Guadalajara','sede'=>'Foro ficticio','inicio'=>(new DateTimeImmutable('+45 minutes',new DateTimeZone('America/Mexico_City')))->format('Y-m-d\\TH:i'),'zona'=>'America/Mexico_City','duracion'=>180]);$event=$result;
        $page=$this->request($a,'evento.php?id='.$event);self::assertSame(200,$page['status']);$csrf=$this->csrf($page['body']);$poster=$this->request($a,'cartel-evento.php?id='.$event);self::assertSame(200,$poster['status']);self::assertStringContainsString('<svg',$poster['body']);self::assertStringContainsString('Cartel HTTP QA',$poster['body']);self::assertStringNotContainsString($this->email,$poster['body']);
        $ics=$this->request($a,'evento-ics.php?id='.$event);self::assertSame(200,$ics['status']);self::assertStringContainsString('SEQUENCE:1',$ics['body']);preg_match('/DTSTART:([^\\r\\n]+)/',$ics['body'],$start);preg_match('/DTEND:([^\\r\\n]+)/',$ics['body'],$end);self::assertSame(10800,strtotime($end[1])-strtotime($start[1]));
        self::assertSame(405,$this->request($a,'evento-accion.php')['status']);self::assertSame(200,$this->request($a,'eventos.php')['status']);self::assertSame(200,$this->request($a,'eventos.php?ver=agenda')['status']);
        $input=['accion'=>'rsvp','id'=>$event,'estado'=>'asistire','aviso'=>'1','csrf'=>$csrf];$saved=$this->request($a,'evento-accion.php','POST',$input,null,['X-Requested-With: MusicMad-PJAX']);self::assertSame(200,$saved['status']);self::assertTrue(json_decode($saved['body'],true)['ok']);
        generarRecordatoriosEventos();$notices=$this->request($a,'notificaciones.php');self::assertSame(200,$notices['status']);self::assertStringContainsString('Se acerca Cartel HTTP QA',$notices['body']);preg_match('/data-open-events="(\\d+)"/',$notices['body'],$until);self::assertGreaterThan(0,(int)$until[1]);self::assertSame(1,contarAvisosEventos($this->uid));
        self::assertSame(200,$this->request($a,'chat-live.php','POST',['accion'=>'avisos','hasta'=>0,'eventos'=>(int)$until[1]],null,['X-MusicMad-CSRF: '.$csrf])['status']);self::assertSame(0,contarAvisosEventos($this->uid));
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado') ON DUPLICATE KEY UPDATE visibilidad='privado'")->execute([$this->uid]);$b=[];$guestAPI=$this->request($b,'cartel-evento.php?id='.$event);self::assertSame(302,$guestAPI['status']);
    }
    public function testMapHTTPAuthCSRFConsentAndSecretFreeCoordinates(): void
    {
        $guest=[];self::assertSame(401,$this->request($guest,'mapa-datos.php?oeste=-105&este=-101&sur=19&norte=22')['status']);$a=[];$this->login($a,false);
        $page=$this->request($a,'ubicacion.php');self::assertSame(200,$page['status']);$data=['accion'=>'guardar','latitud'=>'20.673541','longitud'=>'-103.348161','consentimiento'=>'1'];self::assertSame(403,$this->request($a,'ubicacion.php','POST',$data)['status']);$data['csrf']=$this->csrf($page['body']);$save=$this->request($a,'ubicacion.php','POST',$data);self::assertSame(200,$save['status']);self::assertStringContainsString('Tu zona aproximada quedó guardada.',$save['body']);
        $result=$this->request($a,'mapa-datos.php?oeste=-105&este=-101&sur=19&norte=22');self::assertSame(200,$result['status']);$points=json_decode($result['body'],true)['puntos'];$row=array_values(array_filter($points,fn($p)=>$p['id']===$this->uid && $p['tipo']==='musico'))[0];self::assertSame(20.7,$row['latitud']);self::assertStringNotContainsString('@musicmad.invalid',$result['body']);self::assertSame(405,$this->request($a,'mapa-datos.php','POST',[])['status']);self::assertSame(422,$this->request($a,'mapa-datos.php?oeste=invalid')['status']);self::assertSame(200,$this->request($a,'mapa.php')['status']);
        self::assertSame(200,$this->request($a,'assets/vendor/leaflet/leaflet.js')['status']);
    }
    public function testMapPolicySurvivesNavigationFromAnotherPage():void
    {
        $cookies=[];$this->login($cookies,false);
        foreach(['feed.php','mapa.php','ubicacion.php'] as $path){
            $page=$this->request($cookies,$path);self::assertSame(200,$page['status']);
            $headers=implode("\n",$page['headers']);
            self::assertStringContainsString("img-src 'self' data: blob: https://tile.openstreetmap.org;",$headers);
            self::assertStringNotContainsString('unsafe-inline',$headers);
        }
        $map=$this->request($cookies,'mapa.php','GET',[],null,['X-Requested-With: MusicMad-PJAX']);
        self::assertSame(200,$map['status']);self::assertStringContainsString('data-map-error',$map['body']);
        self::assertStringContainsString('data-map-retry',$map['body']);
        foreach(['assets/vendor/leaflet/leaflet.js','assets/vendor/leaflet/leaflet.css','js/scene-map.js'] as $asset)self::assertSame(200,$this->request($cookies,$asset)['status']);
        foreach(['includes/mailer.php','vendor/autoload.php','config/correo.example.php','bin/jobs.php'] as $private)self::assertSame(404,$this->request($cookies,$private)['status']);
    }
    public function testVideoRestorePageIsOwnedAndMissingUploadCsrfIsRejected():void
    {
        $cookies=[];$guest=[];$created=crearPublicacion($this->uid,['tipo'=>'video','titulo'=>'Restauración HTTP QA']);$pub=(int)$created['id'];
        getDB()->prepare('INSERT INTO publicacion_video(publicacion_id,archivo,formato,bytes) VALUES(?,?,?,?)')->execute([$pub,bin2hex(random_bytes(12)).'.webm','webm',100]);
        self::assertSame(302,$this->request($guest,'restaurar-video.php?id='.$pub)['status']);$this->login($cookies,false);
        $page=$this->request($cookies,'restaurar-video.php?id='.$pub);self::assertSame(200,$page['status']);self::assertStringContainsString('name="restaurar_publicacion" value="'.$pub.'"',$page['body']);self::assertStringContainsString('data-chunk-upload',$page['body']);self::assertStringContainsString('no-store',implode("\n",$page['headers']));
        self::assertSame(405,$this->request($cookies,'restaurar-video.php?id='.$pub,'POST')['status']);
        self::assertSame(403,$this->request($cookies,'subir-archivo.php?op=publicar','POST',['restaurar_publicacion'=>$pub])['status']);
        self::assertSame(404,$this->request($cookies,'video.php?id='.$pub)['status']);
        $other=crearPublicacion(1,['tipo'=>'video','titulo'=>'Otro video privado HTTP QA']);try{self::assertSame(404,$this->request($cookies,'restaurar-video.php?id='.$other['id'])['status']);}finally{getDB()->prepare('DELETE FROM publicaciones WHERE id=?')->execute([$other['id']]);}
    }
    public function testAudioHeadExplainsMissingFileOnlyToOwnerAndRestoresPrivateCopy():void
    {
        $cookies=[];$this->login($cookies,false);$pub=(int)crearPublicacion($this->uid,['tipo'=>'cancion','titulo'=>'Audio anterior HTTP QA'])['id'];$name=bin2hex(random_bytes(12)).'.wav';$source=AUDIO_DIR.'/'.$name;$backup=carpetaRespaldoMedios('audio').'/'.$name;
        $bytes=file_get_contents(musicmadQaRoot().'/uploads/audio/qa-tone.wav');getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,?)')->execute([$pub,$name,strlen($bytes)]);
        try{
            $missing=$this->request($cookies,'audio.php?id='.$pub,'HEAD');self::assertSame(404,$missing['status']);self::assertContains('X-MusicMad-Audio-Status: missing-file',$missing['headers']);self::assertSame('',$missing['body']);
            file_put_contents($source,$bytes);respaldarMedioGuardado('audio',$name);unlink($source);clearstatcache(true,$source);
            $ready=$this->request($cookies,'audio.php?id='.$pub,'HEAD');self::assertSame(200,$ready['status']);self::assertSame('',$ready['body']);self::assertStringContainsString('audio/wav',implode("\n",$ready['headers']));self::assertSame(hash('sha256',$bytes),hash_file('sha256',$source));
            $range=$this->request($cookies,'audio.php?id='.$pub,'GET',[],null,['Range: bytes=0-15']);self::assertSame(206,$range['status']);self::assertSame(substr($bytes,0,16),$range['body']);
            getDB()->prepare('UPDATE publicacion_audio SET archivo=? WHERE publicacion_id=?')->execute(['../config/local.php',$pub]);$invalid=$this->request($cookies,'audio.php?id='.$pub,'HEAD');self::assertSame(404,$invalid['status']);self::assertContains('X-MusicMad-Audio-Status: invalid-record',$invalid['headers']);
            getDB()->prepare('UPDATE publicaciones SET usuario_id=1 WHERE id=?')->execute([$pub]);$other=$this->request($cookies,'audio.php?id='.$pub,'HEAD');self::assertSame(404,$other['status']);self::assertStringNotContainsString('X-MusicMad-Audio-Status:',implode("\n",$other['headers']));
        }finally{getDB()->prepare('DELETE FROM publicaciones WHERE id=?')->execute([$pub]);foreach([$source,$backup] as $file)if(is_file($file)&&!is_link($file))unlink($file);}
    }
    public function testMatchingPagesAndPrivateRequestOwnership(): void
    {
        $a=[];$this->login($a,false);self::assertSame(200,$this->request($a,'busco.php')['status']);$page=$this->request($a,'compatibles.php?instrumento=baterista&ciudad=Guadalajara');self::assertSame(200,$page['status']);self::assertStringNotContainsString('@musicmad.invalid',$page['body']);
        $r=crearBusqueda($this->uid,['instrumento'=>'baterista','ciudad'=>'Guadalajara','nivel'=>'sin_definir']);self::assertTrue($r['ok']);self::assertSame(200,$this->request($a,'compatibles.php?busqueda='.$r['id'])['status']);$other=crearBusqueda(1,['instrumento'=>'guitarra']);try{self::assertSame(404,$this->request($a,'compatibles.php?busqueda='.$other['id'])['status']);}finally{getDB()->prepare('DELETE FROM busquedas WHERE id=?')->execute([$other['id']]);}
    }
    public function testBandHTTPMembershipCSRFReadCursorAndCalendar(): void
    {
        $guest=[];self::assertSame(401,$this->request($guest,'banda-chat.php?con=1')['status']);$a=[];$this->login($a,false);
        $r=crearBanda($this->uid,['nombre'=>'HTTP band '.bin2hex(random_bytes(5))]);self::assertTrue($r['ok']);$band=$r['id'];$page=$this->request($a,'banda.php?id='.$band);self::assertSame(200,$page['status']);$csrf=$this->csrf($page['body']);
        self::assertSame(200,$this->request($a,'banda-chat-vista.php?id='.$band)['status']);
        $input=['accion'=>'enviar','con'=>$band,'texto'=>'Grupo HTTP QA','solicitud'=>bin2hex(random_bytes(16))];self::assertSame(403,$this->request($a,'banda-chat.php','POST',$input)['status']);
        $first=$this->request($a,'banda-chat.php','POST',$input,null,['X-MusicMad-CSRF: '.$csrf]);self::assertSame(200,$first['status']);$message=json_decode($first['body'],true)['mensaje'];$again=$this->request($a,'banda-chat.php','POST',$input,null,['X-MusicMad-CSRF: '.$csrf]);self::assertSame($message['id'],json_decode($again['body'],true)['mensaje']['id']);
        self::assertSame(200,$this->request($a,'banda-chat.php?con='.$band)['status']);$st=getDB()->prepare('SELECT COUNT(*) FROM musicmad_band_chat_reads WHERE banda_id=?');$st->execute([$band]);self::assertSame(0,(int)$st->fetchColumn());
        self::assertSame(200,$this->request($a,'banda-chat.php','POST',['accion'=>'leer','con'=>$band,'hasta'=>$message['id']],null,['X-MusicMad-CSRF: '.$csrf])['status']);$st->execute([$band]);self::assertSame(1,(int)$st->fetchColumn());
        $calendar=$this->request($a,'banda-ics.php?id='.$band);self::assertSame(200,$calendar['status']);self::assertStringContainsString('BEGIN:VCALENDAR',$calendar['body']);self::assertContains('Cache-Control: private, no-store',$calendar['headers']);
        self::assertSame(405,$this->request($a,'banda-accion.php')['status']);self::assertSame(200,$this->request($a,'bandas.php')['status']);self::assertSame(200,$this->request($a,'banda.php?id='.$band.'&tab=integrantes')['status']);self::assertSame(200,$this->request($a,'banda.php?id='.$band.'&tab=agenda')['status']);self::assertSame(200,$this->request($a,'banda.php?id='.$band.'&tab=gestion')['status']);
        $other=crearBanda(1,['nombre'=>'HTTP other '.bin2hex(random_bytes(5))]);try{self::assertSame(404,$this->request($a,'banda-chat.php?con='.$other['id'])['status']);self::assertSame(404,$this->request($a,'banda-ics.php?id='.$other['id'])['status']);}finally{getDB()->prepare('DELETE FROM bandas WHERE id=?')->execute([$other['id']]);}
        archivarBandaWorkspace($this->uid,$band);self::assertSame(404,$this->request($a,'banda-chat.php?con='.$band)['status']);
    }
    public function testGlobalSearchReturnsOnlyPublicFieldsAndEnforcesAuthentication(): void
    {
        $guest=[];self::assertSame(401,$this->request($guest,'busqueda-global.php?q=Emiliano')['status']);$a=[];$this->login($a,false);
        $result=$this->request($a,'busqueda-global.php?q=Emiliano');self::assertSame(200,$result['status']);$data=json_decode($result['body'],true);self::assertTrue($data['ok']);self::assertContains(1,array_column($data['resultados']['musicos'],'id'));self::assertStringNotContainsString('@musicmad.invalid',$result['body']);
        self::assertSame(405,$this->request($a,'busqueda-global.php','POST',[])['status']);self::assertSame(200,$this->request($a,'buscar.php?q=Emiliano')['status']);
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(?,1,'bloquear')")->execute([$this->uid]);$hidden=$this->request($a,'busqueda-global.php?q=Emiliano');self::assertSame(200,$hidden['status']);self::assertNotContains(1,array_column(json_decode($hidden['body'],true)['resultados']['musicos'],'id'));
    }
    public function testPushSettingsRequireCSRFAndConfigurationDoesNotExposeSecrets(): void
    {
        $guest=[];self::assertSame(401,$this->request($guest,'push-suscripcion.php')['status']);$a=[];$this->login($a,false);
        $page=$this->request($a,'avisos-dispositivo.php');self::assertSame(200,$page['status']);preg_match('/name="musicmad-csrf" content="([a-f0-9]{64})"/',$page['body'],$match);self::assertNotEmpty($match[1]);$csrf=$match[1];
        $config=$this->request($a,'push-suscripcion.php');self::assertSame(200,$config['status']);$config=json_decode($config['body'],true);self::assertFalse($config['disponible']);self::assertSame('',$config['clave_publica']);self::assertArrayNotHasKey('clave_privada',$config);
        $input=['accion'=>'preferencias']+array_fill_keys(PUSH_CATEGORIES,false);self::assertSame(403,$this->request($a,'push-suscripcion.php','POST',$input)['status']);
        self::assertSame(200,$this->request($a,'push-suscripcion.php','POST',$input,null,['X-MusicMad-CSRF: '.$csrf])['status']);
        self::assertSame(503,$this->request($a,'push-suscripcion.php','POST',['accion'=>'activar','suscripcion'=>[]],null,['X-MusicMad-CSRF: '.$csrf])['status']);
    }
    public function testChatTransportRequiresSessionCSRFAndDoesNotMarkOnGET(): void
    {
        $guest=[];self::assertSame(401,$this->request($guest,'chat-live.php?con=1')['status']);$a=[];$this->login($a,false);
        $page=$this->request($a,'mensajes.php?con=1');self::assertSame(200,$page['status']);$csrf=$this->csrf($page['body']);
        $db=getDB();$db->prepare('INSERT INTO mensajes(de_id,para_id,texto) VALUES(1,?,?)')->execute([$this->uid,'HTTP read QA']);$id=(int)$db->lastInsertId();
        self::assertSame(200,$this->request($a,'chat-live.php?con=1')['status']);$st=$db->prepare('SELECT leido FROM mensajes WHERE id=?');$st->execute([$id]);self::assertSame(0,(int)$st->fetchColumn());
        self::assertSame(403,$this->request($a,'chat-live.php','POST',['accion'=>'leer','con'=>1,'hasta'=>$id])['status']);
        self::assertSame(200,$this->request($a,'chat-live.php','POST',['accion'=>'leer','con'=>1,'hasta'=>$id],null,['X-MusicMad-CSRF: '.$csrf])['status']);$st->execute([$id]);self::assertSame(1,(int)$st->fetchColumn());
        $body=['accion'=>'enviar','con'=>1,'texto'=>'HTTP send QA','solicitud'=>bin2hex(random_bytes(16))];
        $first=$this->request($a,'chat-live.php','POST',$body,null,['X-MusicMad-CSRF: '.$csrf]);self::assertSame(200,$first['status']);$again=$this->request($a,'chat-live.php','POST',$body,null,['X-MusicMad-CSRF: '.$csrf]);self::assertSame(json_decode($first['body'],true)['mensaje']['id'],json_decode($again['body'],true)['mensaje']['id']);
    }
    public function testGlobalLogoutRevokesOtherBrowserRememberAndJWT(): void
    {
        $a=[];$b=[];$api=[];$this->login($a);$this->login($b);
        self::assertArrayHasKey('MUSICMADREMEMBER',$b);
        $jwt=$this->request($api,'api/v1/login','POST',['email'=>$this->email,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE]);self::assertSame(200,$jwt['status']);$token=json_decode($jwt['body'],true)['access_token'];
        $form=$this->request($a,'configuracion.php');self::assertSame(200,$form['status']);
        $close=$this->request($a,'configuracion.php','POST',['csrf'=>$this->csrf($form['body']),'accion'=>'revocar_otros']);self::assertSame(200,$close['status']);
        self::assertSame(200,$this->request($a,'feed.php')['status']);
        self::assertSame(302,$this->request($b,'feed.php')['status']);
        self::assertSame(401,$this->request($api,'api/v1/me','GET',[],$token)['status']);
    }
    public function testPasswordChangeInvalidatesOpenSessionAndJWT(): void
    {
        $a=[];$b=[];$api=[];$this->login($a,false);$this->login($b,false);
        $jwt=$this->request($api,'api/v1/login','POST',['email'=>$this->email,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE]);$token=json_decode($jwt['body'],true)['access_token'];
        $form=$this->request($a,'configuracion.php');$new='MusicMad_HTTP_New_QA_42!';
        $result=$this->request($a,'configuracion.php','POST',['csrf'=>$this->csrf($form['body']),'accion'=>'cambiar_REPLACE_WITH_LOCAL_VALUE','actual'=>$this->REPLACE_WITH_LOCAL_VALUE,'nueva'=>$new,'confirmar'=>$new]);self::assertSame(200,$result['status']);
        self::assertStringContainsString('Contraseña actualizada.',$result['body']);self::assertSame(200,$this->request($a,'feed.php')['status']);self::assertSame(302,$this->request($b,'feed.php')['status']);
        self::assertSame(401,$this->request($api,'api/v1/me','GET',[],$token)['status']);
    }
    public function testFailedLoginPersistsRateLimitInsideTransaction(): void
    {
        $a=[];$form=$this->request($a,'login.php');$csrf=$this->csrf($form['body']);
        $failed=$this->request($a,'login.php','POST',['csrf'=>$csrf,'email'=>$this->email,'REPLACE_WITH_LOCAL_VALUE'=>'Wrong_QA_Only']);
        self::assertSame(200,$failed['status']);self::assertStringContainsString('Credenciales incorrectas',$failed['body']);
        $fingerprint=hash_hmac('sha256',mb_strtolower($this->email).chr(0).'127.0.0.1',str_repeat('qa-test-key-',6));
        $st=getDB()->prepare('SELECT attempts FROM login_attempts WHERE fingerprint=?');$st->execute([$fingerprint]);self::assertSame(1,(int)$st->fetchColumn());
        getDB()->prepare('DELETE FROM login_attempts WHERE fingerprint=?')->execute([$fingerprint]);
    }
    public function testAdminDemotionIsEffectiveInAnOpenBrowserSession(): void
    {
        getDB()->prepare("UPDATE usuarios SET rol='administrador' WHERE id=?")->execute([$this->uid]);
        $a=[];$this->login($a,false);self::assertSame(200,$this->request($a,'moderacion.php')['status']);
        getDB()->prepare("UPDATE usuarios SET rol='cliente' WHERE id=?")->execute([$this->uid]);
        self::assertSame(403,$this->request($a,'moderacion.php')['status']);
    }
    public function testActionEndpointRequiresOwnerScopeAndFreshCSRFAndReplaysReceipt(): void
    {
        $a=[];$this->login($a,false);$auth=$this->request($a,'acciones-pendientes.php');self::assertSame(200,$auth['status']);$auth=json_decode($auth['body'],true);
        $pub=crearPublicacion($this->uid,['tipo'=>'texto','titulo'=>'HTTP action QA'])['id'];$action=['solicitud'=>bin2hex(random_bytes(16)),'accion'=>'comentar','publicacion'=>$pub,'texto'=>'Comentario HTTP QA'];
        $body=['owner'=>$this->uid,'scope'=>$auth['scope'],'acciones'=>[$action]];$headers=['X-MusicMad-CSRF: '.$auth['csrf']];
        self::assertSame(403,$this->request($a,'acciones-pendientes.php','POST',$body)['status']);
        $wrong=$body;$wrong['owner']=1;self::assertSame(409,$this->request($a,'acciones-pendientes.php','POST',$wrong,null,$headers)['status']);
        $first=$this->request($a,'acciones-pendientes.php','POST',$body,null,$headers);self::assertSame(200,$first['status']);self::assertFalse(json_decode($first['body'],true)['resultados'][0]['repetida']);
        $repeat=$this->request($a,'acciones-pendientes.php','POST',$body,null,$headers);self::assertSame(200,$repeat['status']);self::assertTrue(json_decode($repeat['body'],true)['resultados'][0]['repetida']);
        $st=getDB()->prepare('SELECT COUNT(*) FROM comentarios WHERE publicacion_id=?');$st->execute([$pub]);self::assertSame(1,(int)$st->fetchColumn());
    }
    private function enableFactorFixture(): string
    {
        getDB()->exec(file_get_contents(dirname(__DIR__).'/config/migrations/006_two_factor_state.sql'));
        $secret=base32Codificar(str_repeat('QA2factor-',2));$previous=getenv('MM_ENC_KEY');
        putenv('MM_ENC_KEY='.str_repeat('qa-test-key-',6));
        try{$cipher=cifrarSecretoMusicMad($secret,'totp',(string)$this->uid);}finally{putenv('MM_ENC_KEY='.$previous);}
        getDB()->prepare('INSERT INTO autenticacion_2fa(usuario_id,secreto_cifrado,habilitada) VALUES(?,?,1)')->execute([$this->uid,$cipher]);
        getDB()->prepare('INSERT INTO musicmad_2fa_state(usuario_id,ultimo_contador) VALUES(?,-1)')->execute([$this->uid]);
        return $secret;
    }
    public function testWebLoginDoesNotCreateAccessBeforeSecondFactor(): void
    {
        $secret=$this->enableFactorFixture();$a=[];$form=$this->request($a,'login.php');
        $first=$this->request($a,'login.php','POST',['csrf'=>$this->csrf($form['body']),'email'=>$this->email,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE,'recordar'=>'0']);self::assertSame(302,$first['status']);self::assertContains('Location: dos-pasos.php',$first['headers']);
        self::assertSame(302,$this->request($a,'feed.php')['status']);$challenge=$this->request($a,'dos-pasos.php');self::assertSame(200,$challenge['status']);
        $done=$this->request($a,'dos-pasos.php','POST',['csrf'=>$this->csrf($challenge['body']),'codigo'=>codigoTotp($secret,time())]);self::assertSame(302,$done['status']);self::assertSame(200,$this->request($a,'feed.php')['status']);
    }
    public function testAPIRequiresSecondFactorAndRejectsReplay(): void
    {
        $secret=$this->enableFactorFixture();$api=[];$login=['email'=>$this->email,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE];
        self::assertSame(401,$this->request($api,'api/v1/login','POST',$login)['status']);
        $login['code']=codigoTotp($secret,time());$first=$this->request($api,'api/v1/login','POST',$login);self::assertSame(200,$first['status']);$token=json_decode($first['body'],true)['access_token'];
        self::assertSame(200,$this->request($api,'api/v1/me','GET',[],$token)['status']);
        self::assertSame(401,$this->request($api,'api/v1/login','POST',$login)['status']);
    }
    public function testFactorEnrollmentRecoveryAndDisableFlow(): void
    {
        $a=[];$b=[];$api=[];$this->login($a,false);$this->login($b,true);
        $jwt=$this->request($api,'api/v1/login','POST',['email'=>$this->email,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE]);$token=json_decode($jwt['body'],true)['access_token'];
        $form=$this->request($a,'seguridad.php');self::assertSame(200,$form['status']);
        $setup=$this->request($a,'seguridad.php','POST',['csrf'=>$this->csrf($form['body']),'accion'=>'preparar','REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE]);self::assertSame(200,$setup['status']);
        preg_match('/class="totp-key">([A-Z2-7]{32})<\\/code>/',$setup['body'],$match);self::assertCount(2,$match,'Debe aparecer la clave únicamente en la cuenta ficticia autenticada.');$secret=$match[1];
        $confirmed=$this->request($a,'seguridad.php','POST',['csrf'=>$this->csrf($setup['body']),'accion'=>'confirmar','codigo'=>codigoTotp($secret,time())]);self::assertSame(200,$confirmed['status']);
        preg_match_all('/<code>([A-Z2-7]{5}(?:-[A-Z2-7]{5}){3})<\\/code>/',$confirmed['body'],$codes);self::assertCount(10,$codes[1]);
        self::assertSame(200,$this->request($a,'feed.php')['status']);self::assertSame(302,$this->request($b,'feed.php')['status']);self::assertSame(401,$this->request($api,'api/v1/me','GET',[],$token)['status']);
        $credentials=['email'=>$this->email,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE,'code'=>$codes[1][0]];
        self::assertSame(200,$this->request($api,'api/v1/login','POST',$credentials)['status']);self::assertSame(401,$this->request($api,'api/v1/login','POST',$credentials)['status']);
        $page=$this->request($a,'seguridad.php');$disabled=$this->request($a,'seguridad.php','POST',['csrf'=>$this->csrf($page['body']),'accion'=>'desactivar','REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE,'codigo'=>$codes[1][1]]);self::assertSame(200,$disabled['status']);
        self::assertFalse(tieneSegundoFactor($this->uid));
        self::assertSame(200,$this->request($api,'api/v1/login','POST',['email'=>$this->email,'REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE])['status']);
    }
    public function testEmailLinkRequiresPOSTAndIsRemovedFromURL(): void
    {
        getDB()->prepare('DELETE FROM musicmad_email_verified WHERE usuario_id=?')->execute([$this->uid]);
        require_once dirname(__DIR__).'/includes/email_account.php';
        $a=[];$this->login($a,false);self::assertTrue(solicitarCorreoCuenta($this->email,'verificar',false));
        $st=getDB()->prepare('SELECT payload,dedupe FROM musicmad_jobs WHERE usuario_id=? ORDER BY id DESC LIMIT 1');$st->execute([$this->uid]);$job=$st->fetch();
        $payload=json_decode(descifrarSecretoMusicMad($job['payload'],'jobs',$job['dedupe']),true);preg_match('/token=([a-f0-9]{64})/',$payload['body'],$match);self::assertCount(2,$match);
        $opened=$this->request($a,'confirmar-correo.php?token='.$match[1]);self::assertSame(302,$opened['status']);self::assertContains('Location: confirmar-correo.php',$opened['headers']);self::assertFalse(correoCuentaVerificado($this->uid));
        $page=$this->request($a,'confirmar-correo.php');self::assertSame(200,$page['status']);self::assertStringNotContainsString($match[1],$page['body']);
        self::assertSame(403,$this->request($a,'confirmar-correo.php','POST',[])['status']);
        $confirmed=$this->request($a,'confirmar-correo.php','POST',['csrf'=>$this->csrf($page['body'])]);self::assertSame(200,$confirmed['status']);self::assertTrue(correoCuentaVerificado($this->uid));
        self::assertNull(contextoTokenCorreo(hash('sha256',$match[1]),'verificar'));
    }
    public function testPasswordResetLinkKeepsFactorAndRevokesOtherAccess(): void
    {
        require_once dirname(__DIR__).'/includes/email_account.php';$this->enableFactorFixture();
        self::assertTrue(solicitarCorreoCuenta($this->email,'recuperar',false));
        $st=getDB()->prepare('SELECT payload,dedupe FROM musicmad_jobs WHERE usuario_id=? ORDER BY id DESC LIMIT 1');$st->execute([$this->uid]);$job=$st->fetch();$payload=json_decode(descifrarSecretoMusicMad($job['payload'],'jobs',$job['dedupe']),true);preg_match('/token=([a-f0-9]{64})/',$payload['body'],$match);self::assertCount(2,$match);
        $a=[];self::assertSame(302,$this->request($a,'restablecer.php?token='.$match[1])['status']);$page=$this->request($a,'restablecer.php');self::assertSame(200,$page['status']);
        $REPLACE_WITH_LOCAL_VALUE='MusicMad_Reset_HTTP_QA_42!';$reset=$this->request($a,'restablecer.php','POST',['csrf'=>$this->csrf($page['body']),'REPLACE_WITH_LOCAL_VALUE'=>$REPLACE_WITH_LOCAL_VALUE,'confirmar'=>$REPLACE_WITH_LOCAL_VALUE]);self::assertSame(302,$reset['status']);
        self::assertTrue(tieneSegundoFactor($this->uid));self::assertNull(contextoTokenCorreo(hash('sha256',$match[1]),'recuperar'));
        $st=getDB()->prepare('SELECT REPLACE_WITH_LOCAL_VALUE FROM usuarios WHERE id=?');$st->execute([$this->uid]);self::assertTrue(REPLACE_WITH_LOCAL_VALUE_verify($REPLACE_WITH_LOCAL_VALUE,$st->fetchColumn()));
    }
    public function testProfileDatabaseFailureIsGenericAndPreservesOldFields(): void
    {
        $cookies=[];$this->login($cookies,false);$page=$this->request($cookies,'perfil.php');self::assertSame(200,$page['status']);
        $db=getDB();$st=$db->prepare('SELECT avatar,bio,color_perfil FROM usuarios WHERE id=?');$st->execute([$this->uid]);$before=$st->fetch();
        $trigger='mm_qa_profile_'.bin2hex(random_bytes(6));
        $db->exec("CREATE TRIGGER `$trigger` BEFORE UPDATE ON usuarios FOR EACH ROW BEGIN IF NEW.id=".$this->uid." AND NEW.bio='QA failure requested' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='FICTITIOUS_PROFILE_DATABASE_SECRET'; END IF; END");
        try {
            $failed=$this->request($cookies,'perfil.php','POST',['csrf'=>$this->csrf($page['body']),'avatar'=>$before['avatar'],'bio'=>'QA failure requested','color'=>'#345678','instrumento'=>'Guitarra','ciudad'=>'Guadalajara','generos'=>['Rock']]);
            self::assertSame(200,$failed['status']);self::assertStringContainsString('No se pudieron guardar los cambios. Inténtalo de nuevo.',$failed['body']);
            foreach (['FICTITIOUS_PROFILE_DATABASE_SECRET','SQLSTATE','PDOException','45000'] as $secret)self::assertStringNotContainsString($secret,$failed['body']);
            $st->execute([$this->uid]);self::assertSame($before,$st->fetch());
            $next=$this->request($cookies,'perfil.php');self::assertSame(200,$next['status']);self::assertStringNotContainsString('QA failure requested',$next['body']);
        } finally { $db->exec("DROP TRIGGER IF EXISTS `$trigger`"); }
    }
    public function testVerificationPageRequiresAdminCsrfAndFreshCredential(): void
    {
        $cookies=[];$this->login($cookies,false);
        self::assertSame(302,$this->request($cookies,'verificaciones.php')['status']);
        // Elevate only this disposable HTTP fixture, then establish a fresh session.
        getDB()->prepare("UPDATE usuarios SET rol='administrador' WHERE id=?")->execute([$this->uid]);revocarAccesosCuenta($this->uid);$cookies=[];$this->login($cookies,false);
        $page=$this->request($cookies,'verificaciones.php?q='.$this->uid);self::assertSame(200,$page['status']);self::assertStringContainsString('Identidad artística',$page['body']);
        self::assertSame(403,$this->request($cookies,'verificaciones.php','POST',['usuario'=>1,'decision'=>'conceder'])['status']);
        self::assertSame(405,$this->request($cookies,'verificaciones.php','PUT')['status']);
        // Bad proof is rejected before any review write; it never targets the existing QA account.
        $r=$this->request($cookies,'verificaciones.php','POST',['csrf'=>$this->csrf($page['body']),'usuario'=>1,'decision'=>'conceder','evidencia'=>'javascript:alert(1)','motivo'=>'identidad_artistica','criterios'=>'1','REPLACE_WITH_LOCAL_VALUE'=>$this->REPLACE_WITH_LOCAL_VALUE]);
        self::assertSame(200,$r['status']);self::assertStringContainsString('referencia pública HTTPS',$r['body']);
    }
    public function testInactiveAccountAndMissingCSRFCannotMutate(): void
    {
        $a=[];$this->login($a,false);
        self::assertSame(403,$this->request($a,'configuracion.php','POST',['accion'=>'revocar_otros'])['status']);
        getDB()->prepare('UPDATE usuarios SET activo=0 WHERE id=?')->execute([$this->uid]);
        self::assertSame(302,$this->request($a,'feed.php')['status']);
    }
    public function testPushQuietPreferencesAreValidatedAndRequireCsrf(): void
    {
        $cookies=[];$this->login($cookies,false);
        $page=$this->request($cookies,'avisos-dispositivo.php');self::assertSame(200,$page['status']);$csrf=$this->csrf($page['body']);
        self::assertStringContainsString('data-push-test',$page['body']);
        $data=['accion'=>'preferencias','silencio'=>['activo'=>true,'desde'=>'21:30','hasta'=>'07:15','zona'=>'America/Mexico_City']]+array_fill_keys(PUSH_CATEGORIES,true);
        self::assertSame(403,$this->request($cookies,'push-suscripcion.php','POST',$data)['status']);
        self::assertSame(200,$this->request($cookies,'push-suscripcion.php','POST',$data,null,['X-MusicMad-CSRF: '.$csrf])['status']);
        $state=json_decode($this->request($cookies,'push-suscripcion.php')['body'],true,512,JSON_THROW_ON_ERROR);
        self::assertSame($data['silencio'],$state['silencio']);self::assertTrue($state['controles']);self::assertArrayNotHasKey('endpoint',$state);
        $data['silencio']['zona']='../../UTC';self::assertSame(422,$this->request($cookies,'push-suscripcion.php','POST',$data,null,['X-MusicMad-CSRF: '.$csrf])['status']);
        self::assertSame('America/Mexico_City',horarioSilenciosoPush($this->uid)['zona']);
        self::assertSame(403,$this->request($cookies,'push-suscripcion.php','POST',['accion'=>'prueba'])['status']);
        self::assertSame(422,$this->request($cookies,'push-suscripcion.php','POST',['accion'=>'prueba'],null,['X-MusicMad-CSRF: '.$csrf])['status']);
    }
    public function testLanguageSelectorCsrfOriginManifestAndApi(): void
    {
        $guest=[];$page=$this->request($guest,'login.php');self::assertSame(200,$page['status']);$csrf=$this->csrf($page['body']);
        self::assertSame(405,$this->request($guest,'idioma.php')['status']);
        self::assertSame(403,$this->request($guest,'idioma.php','POST',['idioma'=>'en','contexto'=>'login'])['status']);
        self::assertSame(403,$this->request($guest,'idioma.php','POST',['csrf'=>$csrf,'idioma'=>'en','contexto'=>'login'],null,['Origin: https://evil.invalid'])['status']);
        self::assertSame(422,$this->request($guest,'idioma.php','POST',['csrf'=>$csrf,'idioma'=>'../en','contexto'=>'login'])['status']);
        self::assertSame(422,$this->request($guest,'idioma.php','POST',['csrf'=>$csrf,'idioma'=>'en','contexto'=>'https://evil.invalid'])['status']);
        $saved=$this->request($guest,'idioma.php','POST',['csrf'=>$csrf,'idioma'=>'en','contexto'=>'login']);self::assertSame(303,$saved['status']);self::assertContains('Location: login.php',$saved['headers']);
        $english=$this->request($guest,'login.php');self::assertStringContainsString('<html lang="en">',$english['body']);self::assertStringContainsString('Your music belongs here.',$english['body']);
        foreach(array_keys(MUSICMAD_LOCALES) as $locale) {
            $manifest=$this->request($guest,'manifesto.php?lang='.$locale);self::assertSame(200,$manifest['status']);$manifest=json_decode($manifest['body'],true,512,JSON_THROW_ON_ERROR);self::assertSame($locale,$manifest['lang']);self::assertSame('./',$manifest['scope']);self::assertSame('./feed.php?utm_source=pwa',$manifest['start_url']);
        }
        self::assertSame(404,$this->request($guest,'manifesto.php?lang=../config/local.php')['status']);
        $api=$this->request($guest,'api/v1/feed','GET',[],null,['Accept-Language: fr']);self::assertSame(401,$api['status']);$body=json_decode($api['body'],true,512,JSON_THROW_ON_ERROR);self::assertSame('unauthorized',$body['error']);self::assertSame('Connectez-vous pour continuer.',$body['message']);
    }
    public function testTranslatedTutorialStillEnforcesServerMinimums(): void
    {
        guardarIdiomaCuenta($this->uid,'en');getDB()->prepare('DELETE FROM musicmad_onboarding_required WHERE usuario_id=?')->execute([$this->uid]);
        $cookies=[];$this->login($cookies,false);$page=$this->request($cookies,'bienvenida.php');self::assertSame(200,$page['status']);
        self::assertStringContainsString('Your scene starts with you.',$page['body']);self::assertStringContainsString('assets/i18n/en.js',$page['body']);$csrf=$this->csrf($page['body']);
        $data=['csrf'=>$csrf,'accion'=>'guardar','paso'=>1,'revision'=>0,'solicitud'=>bin2hex(random_bytes(16)),'instrumento'=>''];
        $result=json_decode($this->request($cookies,'bienvenida-accion.php','POST',$data,null,['X-Requested-With: MusicMad-PJAX'])['body'],true,512,JSON_THROW_ON_ERROR);
        self::assertFalse($result['ok']);self::assertSame('Enter an instrument using 2–60 characters.',$result['flash'][1]);self::assertSame(1,(int)estadoOnboarding($this->uid)['paso']);
        $data['instrumento']='Viola QA';$data['solicitud']=bin2hex(random_bytes(16));
        $result=json_decode($this->request($cookies,'bienvenida-accion.php','POST',$data,null,['X-Requested-With: MusicMad-PJAX'])['body'],true,512,JSON_THROW_ON_ERROR);self::assertTrue($result['ok']);
        $data=['csrf'=>$csrf,'accion'=>'guardar','paso'=>2,'revision'=>(int)estadoOnboarding($this->uid)['revision'],'solicitud'=>bin2hex(random_bytes(16)),'generos'=>['Rock']];
        $result=json_decode($this->request($cookies,'bienvenida-accion.php','POST',$data,null,['X-Requested-With: MusicMad-PJAX'])['body'],true,512,JSON_THROW_ON_ERROR);
        self::assertFalse($result['ok']);self::assertSame('Choose at least three different genres.',$result['flash'][1]);self::assertSame(2,(int)estadoOnboarding($this->uid)['paso']);
    }
}
