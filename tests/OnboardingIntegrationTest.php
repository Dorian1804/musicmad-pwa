<?php

use PHPUnit\Framework\TestCase;

final class OnboardingIntegrationTest extends TestCase
{
    private array $users = [];

    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') $this->markTestSkipped('Solo QA aislada.');
        self::assertSame('musicmad_qa', DB_NAME);
        foreach (['Nuevo','Guitarra','Voz','Bajo','Otro'] as $name) {
            $email = 'required-onboard-' . bin2hex(random_bytes(8)) . '@musicmad.invalid';
            getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute([$name,'QA',$email,REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_Onboarding_QA_42!',PASSWORD_BCRYPT)]);
            $this->users[] = $uid = (int) getDB()->lastInsertId();
            marcarCorreoGoogleVerificado($uid,$email,true);
        }
    }

    protected function tearDown(): void
    {
        if (getDB()->inTransaction()) getDB()->rollBack();
        foreach ($this->users as $uid) getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$uid]);
    }

    private function form(string $action='guardar', array $data=[]): array
    {
        $state = estadoOnboarding($this->users[0]);
        return $data + ['accion'=>$action,'paso'=>(int)$state['paso'],'revision'=>(int)$state['revision'],'solicitud'=>bin2hex(random_bytes(16))];
    }

    private function send(array $data): void { actualizarOnboarding($this->users[0],$this->form('guardar',$data)); }
    private function instrumentAndGenres(): void
    {
        $this->send(['instrumento'=>'Viola, guitarra','ciudad'=>'Guadalajara']);
        $this->send(['generos'=>['Rock','Jazz','Pop']]);
    }

    public function testLegacyIsPendingWithoutReadWritesOrInheritedOptionalCompletion(): void
    {
        $uid=$this->users[0];
        getDB()->prepare('INSERT INTO musicmad_onboarding(usuario_id,paso,completado_en) VALUES(?,4,UTC_TIMESTAMP())')->execute([$uid]);
        self::assertSame(1,(int)estadoOnboarding($uid)['paso']);
        self::assertSame(0,(int)estadoOnboarding($uid)['revision']);
        self::assertFalse(onboardingCompleto($uid));
        self::assertSame('bienvenida.php',destinoAccesoMusicMad($uid,'publicacion.php?id=9'));
        $st=getDB()->prepare('SELECT COUNT(*) FROM musicmad_onboarding_required WHERE usuario_id=?');$st->execute([$uid]);self::assertSame(0,(int)$st->fetchColumn());
    }

    public function testFiveStepsPersistRealPreferencesAndFinishOnlyAfterAliasAndDeviceReview(): void
    {
        $uid=$this->users[0];$this->instrumentAndGenres();
        $follow=$this->form('guardar',['musicos'=>array_slice($this->users,1,3)]);
        actualizarOnboarding($uid,$follow);actualizarOnboarding($uid,$follow);
        self::assertSame(4,(int)estadoOnboarding($uid)['paso']);self::assertFalse(onboardingCompleto($uid));
        self::assertSame('Rock, Jazz, Pop',obtenerPerfilMusical($uid)['generos']);self::assertSame('Viola, guitarra',obtenerPerfilMusical($uid)['instrumento']);
        self::assertSame('Guadalajara',obtenerPerfilMusical($uid)['ciudad']);
        foreach(array_slice($this->users,1,3) as $other) self::assertSame(1,estadisticasMusico($other,$uid)['lo_sigo']);
        self::assertSame(0,estadisticasMusico($this->users[4],$uid)['lo_sigo']);
        $this->send(['slug'=>'onboard_' . bin2hex(random_bytes(8))]);self::assertFalse(onboardingCompleto($uid));
        $this->send(['notificaciones'=>'servicio_pendiente','instalacion'=>'instrucciones','dispositivo_revisado'=>'1']);
        self::assertTrue(onboardingCompleto($uid));self::assertNotNull(estadoOnboarding($uid)['completado_en']);
        self::assertSame('publicacion.php?id=9',destinoAccesoMusicMad($uid,'publicacion.php?id=9'));
    }

    public function testCannotOmitForgeStepOrCountDuplicatesAsThreeGenresOrProfiles(): void
    {
        $uid=$this->users[0];
        foreach([$this->form('omitir'),$this->form('guardar',['paso'=>5,'notificaciones'=>'sin_soporte','instalacion'=>'instrucciones','dispositivo_revisado'=>'1'])] as $data){
            try {actualizarOnboarding($uid,$data);self::fail('Skipped required step');}catch(LogicException $error){}
        }
        self::assertSame(1,(int)estadoOnboarding($uid)['paso']);
        $this->send(['instrumento'=>'Piano']);
        foreach([['Jazz'],['Jazz','Jazz','Jazz'],['Rock','Jazz','invalido']] as $genres){
            try{$this->send(['generos'=>$genres]);self::fail('Invalid genres accepted');}catch(InvalidArgumentException $error){}
        }
        $this->send(['generos'=>['Rock','Jazz','Pop']]);
        try{$this->send(['musicos'=>[$this->users[1],$this->users[1],$this->users[2]]]);self::fail('Duplicate profiles accepted');}catch(InvalidArgumentException $error){}
        self::assertSame(3,(int)estadoOnboarding($uid)['paso']);
    }

    public function testPrivateBlockedSelfAndInactiveTargetsRollbackAllFollows(): void
    {
        $uid=$this->users[0];$other=$this->users[3];$this->instrumentAndGenres();
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$other]);
        try{$this->send(['musicos'=>array_slice($this->users,1,3)]);self::fail('Private profile accepted');}catch(OutOfBoundsException $error){}
        self::assertSame(0,estadisticasMusico($this->users[1],$uid)['lo_sigo']);
        getDB()->prepare('DELETE FROM perfiles_privacidad WHERE usuario_id=?')->execute([$other]);
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(?,?,'bloquear')")->execute([$other,$uid]);
        try{$this->send(['musicos'=>array_slice($this->users,1,3)]);self::fail('Blocked profile accepted');}catch(OutOfBoundsException $error){}
        try{$this->send(['musicos'=>[$uid,$this->users[1],$this->users[2]]]);self::fail('Self accepted');}catch(OutOfBoundsException $error){}
        self::assertSame(3,(int)estadoOnboarding($uid)['paso']);
    }

    public function testReceiptConflictStaleTabAndRestartPreservePreviousFields(): void
    {
        $uid=$this->users[0];$data=$this->form('guardar',['instrumento'=>'Bajo','ciudad'=>'Puebla']);$stale=$this->form('comenzar');
        actualizarOnboarding($uid,$data);actualizarOnboarding($uid,$data);
        $data['instrumento']='Bateria';try{actualizarOnboarding($uid,$data);self::fail('Changed receipt');}catch(UnexpectedValueException $error){}
        try{actualizarOnboarding($uid,$stale);self::fail('Stale tab');}catch(DomainException $error){}
        actualizarOnboarding($uid,$this->form('comenzar'));self::assertSame(1,(int)estadoOnboarding($uid)['paso']);
        self::assertSame('Bajo',obtenerPerfilMusical($uid)['instrumento']);self::assertSame('Puebla',obtenerPerfilMusical($uid)['ciudad']);
    }

    public function testAliasCollisionDoesNotReassignOwnerOrAllowActivePushClaim(): void
    {
        $uid=$this->users[0];$other=$this->users[4];$this->instrumentAndGenres();$this->send(['musicos'=>array_slice($this->users,1,3)]);
        $slug='taken_' . bin2hex(random_bytes(8));getDB()->prepare('INSERT INTO perfiles_publicos(usuario_id,slug) VALUES(?,?)')->execute([$other,$slug]);
        try{$this->send(['slug'=>$slug]);self::fail('Alias collision accepted');}catch(InvalidArgumentException $error){}
        $st=getDB()->prepare('SELECT usuario_id FROM perfiles_publicos WHERE slug=?');$st->execute([$slug]);self::assertSame($other,(int)$st->fetchColumn());
        self::assertSame(4,(int)estadoOnboarding($uid)['paso']);$this->send(['slug'=>'own_' . bin2hex(random_bytes(8))]);
        try{$this->send(['notificaciones'=>'activa','instalacion'=>'standalone','dispositivo_revisado'=>'1']);self::fail('False push claim');}catch(DomainException $error){}
        self::assertFalse(onboardingCompleto($uid));
    }

    public function testFinalMinimumIsCheckedAgainAndMigrationPreservesData(): void
    {
        $uid=$this->users[0];$this->instrumentAndGenres();$this->send(['musicos'=>array_slice($this->users,1,3)]);$this->send(['slug'=>'final_' . bin2hex(random_bytes(8))]);
        getDB()->prepare('DELETE FROM seguidores WHERE seguidor_id=? AND seguido_id=?')->execute([$uid,$this->users[1]]);
        try{$this->send(['notificaciones'=>'servicio_pendiente','instalacion'=>'instrucciones','dispositivo_revisado'=>'1']);self::fail('Missing minimum accepted');}catch(DomainException $error){}
        $sql=file_get_contents(dirname(__DIR__).'/config/migrations/033_required_onboarding.sql');getDB()->exec($sql);getDB()->exec($sql);
        self::assertSame(5,(int)estadoOnboarding($uid)['paso']);self::assertFalse(onboardingCompleto($uid));
    }
    public function testGenreChoicesRankCompatibleSuggestions(): void
    {
        $uid=$this->users[0];$this->instrumentAndGenres();
        foreach([$this->users[1]=>'Jazz',$this->users[2]=>'Rock, Jazz, Pop',$this->users[3]=>'Metal'] as $other=>$genres) {
            getDB()->prepare('INSERT INTO perfiles_musicales(usuario_id,instrumento,generos) VALUES(?,?,?)')->execute([$other,'Guitarra',$genres]);
        }
        $ids=array_column(musicosSugeridos($uid,100),'id');
        self::assertLessThan(array_search($this->users[1],$ids),array_search($this->users[2],$ids));
        self::assertLessThan(array_search($this->users[3],$ids),array_search($this->users[1],$ids));
    }

}
