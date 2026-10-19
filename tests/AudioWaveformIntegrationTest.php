<?php

use PHPUnit\Framework\TestCase;

final class AudioWaveformIntegrationTest extends TestCase
{
    private int $uid = 0;
    private int $pub = 0;
    private string $path = '';
    private string $hash = '';
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute(['Forma','QA','wave_' . bin2hex(random_bytes(8)) . '@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_Wave_QA_42!', PASSWORD_BCRYPT)]);
        $this->uid = (int)getDB()->lastInsertId();
        $file = 'wave_test_' . bin2hex(random_bytes(8)) . '.wav';
        $this->path = AUDIO_DIR . '/' . $file;
        $samples = '';
        for ($i = 0;$i < 8192;$i++) {
            $samples .= pack('v2', 8000, 65536 - 24000);
        }
        $bytes = strlen($samples);
        $wav = 'RIFF' . pack('V', 36 + $bytes) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 2, 8000, 32000, 4, 16) . 'data' . pack('V', $bytes) . $samples;
        file_put_contents($this->path, $wav);
        $this->hash = hash_file('sha256', $this->path);
        getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'cancion','Onda QA')")->execute([$this->uid]);
        $this->pub = (int)getDB()->lastInsertId();
        getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,?)')->execute([$this->pub,$file,strlen($wav)]);
        guardarMetadatosMedio($this->pub, ['sha256' => $this->hash,'duracion' => 1.024,'mime' => 'audio/wav','ancho' => 0,'alto' => 0,'codec' => 'PCM']);
    }
    protected function tearDown(): void
    {
        if (getDB()->inTransaction()) {
            getDB()->rollBack();
        }
        if ($this->uid) {
            getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$this->uid]);
        }
        if ($this->path && is_file($this->path) && str_contains($this->path, 'wave_test_')) {
            unlink($this->path);
        }
    }
    public function testLegacyAudioWithoutMetadataCanGenerateItsWaveform(): void
    {
        getDB()->prepare('DELETE FROM publicacion_medios WHERE publicacion_id=?')->execute([$this->pub]);
        self::assertNull(datosFormaOnda($this->uid, $this->pub)['duracion']);
        procesarFormaOnda($this->uid, $this->pub);
        $data = datosFormaOnda($this->uid, $this->pub);
        self::assertEqualsWithDelta(1.024, $data['duracion'], .001);
        self::assertCount(512, $data['onda']['picos']);
        self::assertSame($this->hash, hash_file('sha256', $this->path));
    }
    public function testNativeWaveformReadsBothChannelsAndKeepsOriginalBytes(): void
    {
        $wave = picosWavLocal($this->path);
        self::assertCount(512, $wave['picos']);
        self::assertSame(732, $wave['picos'][0]);
        self::assertSame(732, $wave['picos'][511]);
        self::assertEqualsWithDelta(1.024, $wave['duracion'], .00001);
        procesarFormaOnda($this->uid, $this->pub);
        $dto = datosFormaOnda($this->uid, $this->pub);
        self::assertSame('servidor', $dto['onda']['origen']);
        self::assertSame($wave['picos'], $dto['onda']['picos']);
        self::assertSame($this->hash, hash_file('sha256', $this->path));
    }
    public function testOnlyOwnerCanPersistPeaksAndCacheIsBoundToFileHash(): void
    {
        $peaks = array_fill(0, 512, 500);
        try {
            guardarFormaOnda(2, $this->pub, $peaks, 1.024, $this->hash);
            self::fail('No guardar pistas ajenas.');
        } catch (OutOfBoundsException $e) {
        }
        try {
            guardarFormaOnda($this->uid, $this->pub, $peaks, 1.024, str_repeat('a', 64));
            self::fail('No aceptar otra huella.');
        } catch (InvalidArgumentException $e) {
        }
        guardarFormaOnda($this->uid, $this->pub, $peaks, 1.024, $this->hash);
        self::assertSame($peaks, datosFormaOnda(2, $this->pub)['onda']['picos']);
        getDB()->prepare("UPDATE publicacion_medios SET sha256=? WHERE publicacion_id=?")->execute([str_repeat('b', 64),$this->pub]);
        self::assertNull(datosFormaOnda(2, $this->pub)['onda']);
    }
    public function testWaveformAndTimedCommentsRespectPrivateTracks(): void
    {
        self::assertTrue(crearComentarioTiempo(2, $this->pub, .345, 'Detalle de prueba'));
        $comments = comentariosTiempoDe([$this->pub], 2);
        self::assertSame(.345, (float)$comments[$this->pub][0]['segundo']);
        self::assertSame('0:00.345', etiquetaMomentoAudio(.345));
        self::assertFalse(crearComentarioTiempo(2, $this->pub, 1.025, 'Fuera de la pista'));
        self::assertFalse(crearComentarioTiempo(2, $this->pub, NAN, 'No válido'));
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$this->uid]);
        self::assertFalse(crearComentarioTiempo(2, $this->pub, 0, 'No autorizado'));
        self::assertSame([], comentariosTiempoDe([$this->pub], 2));
        try {
            datosFormaOnda(2, $this->pub);
            self::fail('No exponer ondas privadas.');
        } catch (OutOfBoundsException $e) {
        }
        // Comments belong to another author and never get exported by the track owner.
        $own = coleccionesExportacionCuenta()['musicmad_timed_comment_ms'];
        $st = getDB()->prepare($own[0]);
        $st->execute([$this->uid]);
        self::assertSame([], $st->fetchAll());
    }
    public function testPeaksRejectSparseNonNumericAndUnboundedInput(): void
    {
        foreach ([[],array_fill(0, 511, 1),array_fill(0, 512, 1.5),array_fill(0, 512, 1001),[1 => 1]] as $peaks) {
            try {
                validarPicosOnda($peaks);
                self::fail('Datos inválidos.');
            } catch (InvalidArgumentException $e) {
            }
        }
        self::assertCount(512, validarPicosOnda(array_fill(0, 512, 0)));
    }
    public function testPreciseCommentRetriesDoNotDuplicateNotificationOrText(): void
    {
        $nonce = bin2hex(random_bytes(16));
        self::assertTrue(crearComentarioTiempo(2, $this->pub, .789, 'Un solo comentario', $nonce));
        self::assertTrue(crearComentarioTiempo(2, $this->pub, .789, 'Un solo comentario', $nonce));
        $st = getDB()->prepare('SELECT COUNT(*) FROM comentarios_tiempo WHERE publicacion_id=?');
        $st->execute([$this->pub]);
        self::assertSame(1,(int)$st->fetchColumn());
        try {
            crearComentarioTiempo(2,$this->pub,.790,'Un solo comentario',$nonce);
            self::fail('No reutilizar con otro momento.');
        } catch (UnexpectedValueException $e) {
        }
    }
    public function testUnsupportedWavDoesNotInventAPreview(): void
    {
        file_put_contents($this->path,'not audio');
        self::assertNull(picosWavLocal($this->path));
    }
}
