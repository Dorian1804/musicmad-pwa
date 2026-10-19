<?php

use PHPUnit\Framework\TestCase;

final class FeedIntegrationTest extends TestCase
{
    private array $ids = [];
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo instancia QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/003_feed_snapshots.sql'));
        for ($i = 0;$i < 32;$i++) {
            $created = crearPublicacion($i % 2 ? 2 : 3, ['tipo' => 'texto','titulo' => 'Feed QA ' . $i,'genero' => $i % 2 ? 'Rock' : 'Jazz']);
            $this->ids[] = $created['id'];
            if ($i < 6) {
                getDB()->prepare('UPDATE publicaciones SET created_at=DATE_SUB(NOW(),INTERVAL 20 DAY) WHERE id=?')->execute([$created['id']]);
                getDB()->prepare('INSERT INTO reposts(usuario_id,publicacion_id) VALUES(?,?)')->execute([2,$created['id']]);
            }
        }
    }
    protected function tearDown(): void
    {
        foreach ($this->ids as $id) {
            getDB()->prepare('DELETE FROM publicaciones WHERE id=?')->execute([$id]);
        }
        if ($this->ids) {
            getDB()->prepare('DELETE FROM musicmad_feed_snapshots WHERE usuario_id=1')->execute();
        }
    }
    public function testCursorDoesNotRepeatOrLoseOldRepostedPosts(): void
    {
        $page = paginaComunidad(1, 'todo', [], '', 5);
        $found = [];
        $iterations = 0;
        do {
            foreach ($page['publicaciones'] as $post) {
                self::assertArrayNotHasKey($post['id'], $found);
                $found[$post['id']] = true;
            }
            if (!$page['cursor']) {
                break;
            }
            $page = paginaComunidad(1, 'todo', [], $page['cursor'], 5);
            self::assertLessThan(30, ++$iterations);
        } while (true);
        foreach ($this->ids as $id) {
            self::assertArrayHasKey($id, $found);
        }
    }
    public function testNewPostDoesNotMoveAnExistingSnapshot(): void
    {
        $first = paginaComunidad(1, 'siguiendo', [], '', 5);
        $new = crearPublicacion(2, ['tipo' => 'texto','titulo' => 'Nueva tras el cursor']);
        $this->ids[] = $new['id'];
        $found = [];
        $page = $first;
        do {
            foreach ($page['publicaciones'] as $post) {
                $found[] = (int)$post['id'];
            }
            if (!$page['cursor']) {
                break;
            }
            $page = paginaComunidad(1, 'siguiendo', [], $page['cursor'], 5);
        } while (true);
        self::assertNotContains($new['id'], $found);
        $fresh = candidatosComunidad(1, normalizarFiltrosFeed('siguiendo', []));
        self::assertContains($new['id'], array_column($fresh, 0));
    }
    public function testUnfollowingIsRecheckedAndRepostCanIntroduceUnfollowedOwner(): void
    {
        $first = paginaComunidad(1, 'siguiendo', [], '', 1);
        $oldFromUnfollowed = array_filter($this->ids, fn ($id) => duenoPublicacion($id) === 3);
        $found = [];
        $page = $first;
        do {
            foreach ($page['publicaciones'] as $post) {
                $found[] = (int)$post['id'];
            }
            if (!$page['cursor']) {
                break;
            }
            $page = paginaComunidad(1, 'siguiendo', [], $page['cursor'], 5);
        } while (true);
        self::assertContains(array_values($oldFromUnfollowed)[0], $found);
        getDB()->prepare('DELETE FROM seguidores WHERE seguidor_id=1 AND seguido_id=2')->execute();
        try {
            $page = paginaComunidad(1, 'siguiendo', [], $first['cursor'], 30);
            // An own repost may still reference an unfollowed author; its actor must be self.
            foreach ($page['publicaciones'] as $post) {
                self::assertTrue((int)$post['usuario_id'] === 1 || (int)($post['repost_usuario_id'] ?? 0) === 1);
            }
        } finally {
            getDB()->prepare('INSERT IGNORE INTO seguidores(seguidor_id,seguido_id) VALUES(1,2)')->execute();
        }
    }
    public function testOriginalAndActorPrivacyIsRecheckedAfterSnapshotCreation(): void
    {
        $first = paginaComunidad(1, 'todo', [], '', 1);
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(1,2,'bloquear') ON DUPLICATE KEY UPDATE modo='bloquear'")->execute();
        try {
            $page = paginaComunidad(1, 'todo', [], $first['cursor'], 30);
            foreach ($page['publicaciones'] as $post) {
                self::assertNotSame(2, (int)$post['usuario_id']);
                self::assertNotSame(2,(int)($post['repost_usuario_id'] ?? 0));
            }
        } finally {
            getDB()->prepare('DELETE FROM usuarios_bloqueados WHERE usuario_id=1 AND objetivo_id=2')->execute();
        }
    }
}
