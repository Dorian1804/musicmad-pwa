<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/action_receipts.php';
final class ActionReceiptsIntegrationTest extends TestCase
{
    private int $pub = 0;
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/005_action_receipts.sql'));
        getDB()->beginTransaction();
        $this->pub = crearPublicacion(1, ['tipo' => 'texto','titulo' => 'Acciones QA'])['id'];
    }
    protected function tearDown(): void
    {
        if (getenv('MM_RUN_QA') === '1' && getDB()->inTransaction()) {
            getDB()->rollBack();
        }
    }
    private function action(string $kind, array $extra = []): array
    {
        return ['solicitud' => bin2hex(random_bytes(16)),'accion' => $kind,'publicacion' => $this->pub] + $extra;
    }
    public function testCommentRetryCreatesOnlyOneComment(): void
    {
        $action = $this->action('comentar', ['texto' => 'Comentario QA idempotente']);
        $first = ejecutarAccionPendiente(2, $action, false);
        $second = ejecutarAccionPendiente(2, $action, false);
        self::assertFalse($first['repetida']);
        self::assertTrue($second['repetida']);
        self::assertSame($first['comentario'], $second['comentario']);
        $st = getDB()->prepare('SELECT COUNT(*) FROM comentarios WHERE publicacion_id=? AND usuario_id=2');
        $st->execute([$this->pub]);
        self::assertSame(1, (int)$st->fetchColumn());
    }
    public function testExplicitStatesDoNotToggleOnReplay(): void
    {
        $action = $this->action('aplaudir', ['estado' => true]);
        ejecutarAccionPendiente(2, $action, false);
        ejecutarAccionPendiente(2, $action, false);
        $st = getDB()->prepare('SELECT COUNT(*) FROM aplausos WHERE usuario_id=2 AND publicacion_id=?');
        $st->execute([$this->pub]);
        self::assertSame(1, (int)$st->fetchColumn());
        ejecutarAccionPendiente(2, $this->action('aplaudir', ['estado' => false]), false);
        $st->execute([$this->pub]);
        self::assertSame(0, (int)$st->fetchColumn());
    }
    public function testReceiptIsBoundToPayloadAndAccount(): void
    {
        $action = $this->action('guardar', ['estado' => true]);
        ejecutarAccionPendiente(1, $action, false);
        self::assertFalse(ejecutarAccionPendiente(2, $action, false)['repetida']);
        $action['estado'] = false;
        $this->expectException(LogicException::class);
        ejecutarAccionPendiente(1, $action, false);
    }
    public function testQueuedActionRechecksPrivacy(): void
    {
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(1,2,'bloquear')")->execute();
        $this->expectException(OutOfBoundsException::class);
        ejecutarAccionPendiente(2, $this->action('guardar', ['estado' => true]), false);
    }
    public function testQueuedReplyCannotTargetAnotherPost(): void
    {
        $db = getDB();
        $db->prepare('INSERT INTO comentarios(publicacion_id,usuario_id,texto) VALUES(?,?,?)')->execute([$this->pub,1,'Padre QA']);
        $parent = (int)$db->lastInsertId();
        $other = crearPublicacion(1, ['tipo' => 'texto','titulo' => 'Otro QA'])['id'];
        $action = $this->action('responder', ['comentario' => $parent,'texto' => 'Respuesta QA']);
        $action['publicacion'] = $other;
        $this->expectException(OutOfBoundsException::class);
        ejecutarAccionPendiente(2,$action,false);
    }
}
