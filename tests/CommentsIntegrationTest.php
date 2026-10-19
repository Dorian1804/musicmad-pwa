<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/comments_engine.php';
final class CommentsIntegrationTest extends TestCase
{
    private int $post = 0;
    private array $comments = [];
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo base QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        $this->post = crearPublicacion(1, ['tipo' => 'texto','titulo' => 'Comentarios QA'])['id'];
        $st = getDB()->prepare('INSERT INTO comentarios(publicacion_id,usuario_id,texto) VALUES(?,?,?)');
        for ($i = 0;$i < 35;$i++) {
            $st->execute([$this->post,$i % 2 ? 2 : 1,'Comentario QA ' . $i]);
            $this->comments[] = (int)getDB()->lastInsertId();
        }
    }
    protected function tearDown(): void
    {
        if (!$this->post) {
            return;
        }
        getDB()->prepare('DELETE FROM publicaciones WHERE id=?')->execute([$this->post]);
        getDB()->prepare('DELETE FROM usuarios_bloqueados WHERE usuario_id=1 AND objetivo_id=2')->execute();
    }
    public function testCursorBoundsAndChronologicalOrder(): void
    {
        $a = paginaComentarios(1, $this->post, 0, 0, 20);
        self::assertCount(20, $a['items']);
        self::assertTrue($a['hay_mas']);
        $b = paginaComentarios(1, $this->post, $a['before'], 0, 20);
        self::assertCount(15, $b['items']);
        self::assertFalse($b['hay_mas']);
        $ids = array_column(array_merge($b['items'], $a['items']), 'id');
        self::assertSame($this->comments, array_map('intval', $ids));
    }
    public function testBlockFiltersInitialAndPaginatedCommentsAndReplies(): void
    {
        $parent = $this->comments[0];
        getDB()->prepare('INSERT INTO respuestas_comentarios(comentario_id,usuario_id,texto) VALUES(?,?,?)')->execute([$parent,2,'Respuesta QA']);
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(1,2,'bloquear')")->execute();
        foreach (paginaComentarios(1, $this->post, 0, 0, 30)['items'] as $row) {
            self::assertSame(1, (int)$row['uid']);
        }
        foreach (comentariosDe([$this->post], 30, 1)[$this->post] as $row) {
            self::assertSame(1, (int)$row['uid']);
        }
        self::assertEmpty(paginaComentarios(1, $this->post, 0, $parent)['items']);
        self::assertEmpty(respuestasComentarios([$parent], 30, 1));
    }
    public function testRepliesCannotReadAnotherPostOrBlockedParent(): void
    {
        $this->expectException(OutOfBoundsException::class);
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(1,2,'bloquear')")->execute();
        self::assertFalse(responderComentario(1, $this->comments[1], 'No permitido'));
        paginaComentarios(1, $this->post, 0, $this->comments[1]);
    }
    public function testForeignParentRejected(): void
    {
        $other = crearPublicacion(1, ['tipo' => 'texto','titulo' => 'Otro contexto QA'])['id'];
        try {
            $this->expectException(OutOfBoundsException::class);
            paginaComentarios(1, $other, 0, $this->comments[0]);
        } finally {
            getDB()->prepare('DELETE FROM publicaciones WHERE id=?')->execute([$other]);
        }
    }
}
