<?php

use PHPUnit\Framework\TestCase;

final class FeedEngineTest extends TestCase
{
    public function testCursorBindsOwnerPositionAndFilters(): void
    {
        $hash = hashFiltrosFeed(normalizarFiltrosFeed('todo', []));
        $id = str_repeat('a', 32);
        $cursor = cursorFeed($id, 10, 1, $hash);
        self::assertSame([$id,10], leerCursorFeed($cursor, 1, $hash));
        $this->expectException(InvalidArgumentException::class);
        leerCursorFeed($cursor, 2, $hash);
    }
    public function testCursorCannotChangeFilters(): void
    {
        $cursor = cursorFeed(str_repeat('b', 32), 10, 1, hashFiltrosFeed(normalizarFiltrosFeed('todo', [])));
        $this->expectException(InvalidArgumentException::class);
        leerCursorFeed($cursor, 1, hashFiltrosFeed(normalizarFiltrosFeed('siguiendo', [])));
    }
    public function testTamperedOffsetIsRejected(): void
    {
        $hash = hashFiltrosFeed(normalizarFiltrosFeed('todo', []));
        $cursor = cursorFeed(str_repeat('c', 32), 10, 1, $hash);
        $this->expectException(InvalidArgumentException::class);
        leerCursorFeed(str_replace('.10.', '.20.', $cursor), 1, $hash);
    }
    public function testUnknownFiltersAreNormalized(): void
    {
        $filters = normalizarFiltrosFeed('injection', ['tipo' => 'bad','genero' => 'unknown','autor' => -1,'instrumento' => str_repeat('a', 100)]);
        self::assertSame('todo', $filters['modo']);
        self::assertSame('', $filters['tipo']);
        self::assertSame(0,$filters['autor']);
        self::assertSame(60,strlen($filters['instrumento']));
    }
}
