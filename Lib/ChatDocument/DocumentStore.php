<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

/**
 * Persistencia de documentos del chat. Las filas viajan como arrays:
 * {id, nick, idempresa, kind, title, status, spec (array), revision,
 *  conversation_id, creation_date, last_update}.
 */
interface DocumentStore
{
    public function find(int $id): ?array;

    /** @return list<array> del usuario, más recientes primero */
    public function listByNick(string $nick, ?string $kind = null, int $limit = 50): array;

    /** Devuelve el id creado o null si falla. */
    public function insert(array $document): ?int;

    public function update(array $document): bool;

    public function delete(int $id): bool;
}
