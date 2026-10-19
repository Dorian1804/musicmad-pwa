<?php

declare(strict_types=1);

namespace MusicMad\Routing;

require_once dirname(__DIR__) . '/social.php';
require_once __DIR__ . '/conversations.php';

/** Server-side resource policy shared by canonical URLs and legacy redirects. */
final class ResourceAccess
{
    public function __construct(private readonly \PDO $db, private readonly int $viewer, private readonly string $roomCode = '')
    {
    }

    private function activeViewer(): bool
    {
        $find = $this->db->prepare('SELECT 1 FROM usuarios WHERE id=? AND activo=1');
        $find->execute([$this->viewer]);
        return (bool) $find->fetchColumn();
    }

    /** Anonymous EPK/share previews have a separate, explicitly public projection. */
    public function canRead(string $type, int $id): bool
    {
        if (!$this->activeViewer()) {
            return false;
        }
        return match ($type) {
            'user' => $this->activeUser($id) && \puedeVerPerfilSocial3($this->viewer, $id),
            'post' => \puedeVerPublicacionSocial3($this->viewer, $id),
            'band' => \bandaDisponible($this->viewer, $id) !== null,
            'event' => \eventoDisponiblePara($this->viewer, $id) !== null,
            'room' => $this->room($id),
            'collab' => \colabDisponiblePara($this->viewer, $id) !== null,
            'clip' => \clipDisponiblePara($this->viewer, $id) !== null,
            'challenge' => \retoPara($this->viewer, $id) !== null,
            'playlist' => \obtenerPlaylistSocial3($id, $this->viewer) !== null,
            'conversation' => (new ConversationReferences($this->db))->otherParticipant($this->viewer, $id) !== null,
            default => false,
        };
    }

    private function activeUser(int $id): bool
    {
        $find = $this->db->prepare('SELECT 1 FROM usuarios WHERE id=? AND activo=1');
        $find->execute([$id]);
        return (bool) $find->fetchColumn();
    }

    private function room(int $id): bool
    {
        $room = \salaDisponiblePara($this->viewer, $id);
        if ($room === null) {
            return false;
        }
        // Preserve old, already-issued invitation URLs without treating the public_id as a key.
        $legacyInvite = preg_match('/^[a-f0-9]{32}$/D', $this->roomCode)
            && hash_equals((string) $room['codigo'], $this->roomCode);
        return $room['modo'] === 'publica' || \integranteSala($this->viewer, $id) || $legacyInvite;
    }

    /** Extra capabilities are checked after resource visibility, never inferred from the URL. */
    public function allows(string $type, int $id, string $capability = 'read'): bool
    {
        if (!$this->canRead($type, $id)) {
            return false;
        }
        return match ($capability) {
            'read' => true,
            'band-member' => $type === 'band' && in_array(\rolEnBanda($this->viewer, $id), ['admin', 'coordinador', 'miembro'], true),
            'band-manage' => $type === 'band' && in_array(\rolEnBanda($this->viewer, $id), ['admin', 'coordinador'], true),
            'band-admin' => $type === 'band' && \rolEnBanda($this->viewer, $id) === 'admin',
            'room-member' => $type === 'room' && \integranteSala($this->viewer, $id),
            'playlist-edit' => $type === 'playlist' && $this->playlist($id, false),
            'playlist-owner' => $type === 'playlist' && $this->playlist($id, true),
            default => false,
        };
    }

    private function playlist(int $id, bool $ownerOnly): bool
    {
        $list = \obtenerPlaylistSocial3($id, $this->viewer);
        return $list !== null && ($ownerOnly ? (int) $list['usuario_id'] === $this->viewer : \puedeEditarPlaylist($this->viewer, $list));
    }

    public function reference(string $type, int $id, string $capability = 'read'): ?string
    {
        return (new PublicReferences($this->db))->forResource($type, $id, fn (string $kind, int $found): bool => $this->allows($kind, $found, $capability));
    }

    public function resolve(string $type, string $reference, string $capability = 'read'): ?int
    {
        return (new PublicReferences($this->db))->resolve($type, $reference, fn (string $kind, int $found): bool => $this->allows($kind, $found, $capability));
    }
}
