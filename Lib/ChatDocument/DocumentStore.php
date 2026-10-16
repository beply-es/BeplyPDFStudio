<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

/**
 * Persistencia de documentos del chat. Las filas viajan como arrays:
 * {id, nick, idempresa, kind, title, status, spec (array), version, revision,
 *  doc_type, conversation_id, creation_date, last_update}. `version` es la versión vigente;
 * las anteriores se guardan aparte ({title, spec, version, creation_date}).
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

    /** Guarda una versión anterior (la que una reescritura completa sustituye). */
    public function insertVersion(int $documentId, int $version, string $title, array $spec): bool;

    /** Versión anterior {version, title, spec, creation_date} o null. */
    public function findVersion(int $documentId, int $version): ?array;

    /**
     * Estilo de un tipo de documento en un ámbito exacto: personal (`nick`) o,
     * con nick null, de la empresa (`idempresa`) o global (ambos null).
     * Fila: {id, nick, idempresa, doc_type, label, overrides (array), last_update}.
     */
    public function findStyle(?string $nick, ?int $idempresa, string $docType): ?array;

    /** Crea o reemplaza el estilo de ese ámbito y tipo. Devuelve su id o null. */
    public function saveStyle(array $style): ?int;

    /** Estilos que ve un usuario: los suyos, los de su empresa y los globales. */
    public function listStyles(string $nick, ?int $idempresa): array;
}
