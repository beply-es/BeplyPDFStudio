<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

/**
 * Documentos del chat (modo Documentos e informes de auditoría) con la regla de
 * propietario: un documento sólo existe para su `nick`. Lo usan el proveedor de
 * tools de Bep y el controlador de vista previa/exportación, así que ambos
 * caminos aplican las mismas reglas.
 */
final class DocumentService
{
    public const ARTIFACT_CONTRACT = 'beply.artifact/v1';
    public const KINDS = ['document', 'audit'];
    public const STATUSES = ['draft', 'saved'];
    public const MAX_VERSIONS = 50;

    public function __construct(private DocumentStore $store)
    {
    }

    public function find(string $nick, int $id): ?array
    {
        $document = $id > 0 ? $this->store->find($id) : null;

        return ($document !== null && $nick !== '' && $document['nick'] === $nick) ? $document : null;
    }

    /**
     * El documento tal como era en `version` (la vigente si es null o la
     * actual). Mismo propietario; null si esa versión no existe.
     */
    public function findVersion(string $nick, int $id, ?int $version): ?array
    {
        $document = $this->find($nick, $id);
        if ($document === null || $version === null || $version === (int) ($document['version'] ?? 1)) {
            return $document;
        }
        $old = $version >= 1 && $version < (int) ($document['version'] ?? 1) ? $this->store->findVersion($id, $version) : null;
        if ($old === null) {
            return null;
        }

        return ['title' => $old['title'], 'spec' => $old['spec'], 'version' => (int) $old['version']] + $document;
    }

    public function list(string $nick, ?string $kind = null, int $limit = 50): array
    {
        $kind = in_array($kind, self::KINDS, true) ? $kind : null;

        return $this->store->listByNick($nick, $kind, max(1, min(200, $limit)));
    }

    /**
     * Crea (sin id) o reemplaza la especificación completa (con id). Cada
     * guardado sube la revisión: el visor del chat la usa para refrescarse.
     * Reemplazar es una versión nueva («Versión N+1»): la anterior se conserva
     * y sigue abriéndose desde su tarjeta del chat. `append` no crea versión.
     *
     * @return array{0: ?array, 1: list<array{path: string, code: string, message: string}>, 2: list<string>}
     */
    public function save(string $nick, ?int $idempresa, $spec, string $kind = 'document', ?int $documentId = null, ?string $conversationId = null, bool $newVersion = true): array
    {
        if (false === in_array($kind, self::KINDS, true)) {
            return [null, [['path' => 'kind', 'code' => 'invalid-value', 'message' => 'kind es document o audit.']], []];
        }
        [$normalized, $errors] = DocumentSpec::validate($spec);
        if ($normalized === null) {
            return [null, $errors, []];
        }
        $warnings = $normalized['_warnings'] ?? [];
        $normalized = DocumentSpec::withoutWarnings($normalized);
        if ($conversationId !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $conversationId) !== 1) {
            $conversationId = null;
        }

        if ($documentId !== null) {
            $document = $this->find($nick, $documentId);
            if ($document === null) {
                return [null, [['path' => 'document_id', 'code' => 'document-not-found', 'message' => 'Ese documento no existe o no es tuyo.']], []];
            }
            $version = max(1, (int) ($document['version'] ?? 1));
            if ($newVersion) {
                if ($version >= self::MAX_VERSIONS) {
                    return [null, [['path' => 'document_id', 'code' => 'too-many-versions', 'message' => 'Este documento ya tiene ' . self::MAX_VERSIONS . ' versiones; crea uno nuevo.']], []];
                }
                if (false === $this->store->insertVersion((int) $document['id'], $version, (string) $document['title'], $document['spec'])) {
                    return [null, [['path' => '', 'code' => 'save-failed', 'message' => 'No se pudo guardar la versión anterior.']], []];
                }
                $document['version'] = $version + 1;
            }
            $document['spec'] = $normalized;
            $document['title'] = $normalized['title'];
            $document['revision'] = (int) $document['revision'] + 1;
            if ($conversationId !== null) {
                $document['conversation_id'] = $conversationId;
            }

            return $this->store->update($document)
                ? [$this->store->find((int) $document['id']), [], $warnings]
                : [null, [['path' => '', 'code' => 'save-failed', 'message' => 'No se pudo guardar el documento.']], []];
        }

        $id = $this->store->insert([
            'nick' => $nick,
            'idempresa' => $idempresa,
            'kind' => $kind,
            'title' => $normalized['title'],
            // El informe de auditoría es un resultado cerrado; el documento, un borrador vivo.
            'status' => $kind === 'audit' ? 'saved' : 'draft',
            'spec' => $normalized,
            'revision' => 1,
            'version' => 1,
            'conversation_id' => $conversationId,
        ]);

        return $id === null
            ? [null, [['path' => '', 'code' => 'save-failed', 'message' => 'No se pudo guardar el documento.']], []]
            : [$this->store->find($id), [], $warnings];
    }

    /**
     * Añade bloques al final de un documento existente (documentos largos: se
     * redactan por secciones sin reenviar el documento entero). Valida el
     * documento completo resultante y sube la revisión como un guardado.
     *
     * @return array{0: ?array, 1: list<array{path: string, code: string, message: string}>, 2: list<string>}
     */
    public function append(string $nick, int $documentId, $blocks, ?string $conversationId = null): array
    {
        $document = $this->find($nick, $documentId);
        if ($document === null) {
            return [null, [['path' => 'document_id', 'code' => 'document-not-found', 'message' => 'Ese documento no existe o no es tuyo.']], []];
        }
        if (false === is_array($blocks) || $blocks === [] || false === array_is_list($blocks)) {
            return [null, [['path' => 'blocks', 'code' => 'required', 'message' => 'Indica la lista de bloques a añadir.']], []];
        }
        $spec = $document['spec'];
        $offset = count($spec['blocks'] ?? []);
        $spec['blocks'] = array_merge($spec['blocks'] ?? [], $blocks);
        [$saved, $errors, $warnings] = $this->save($nick, null, $spec, (string) $document['kind'], $documentId, $conversationId, false);
        if ($saved === null) {
            // Las rutas de error apuntan a los bloques NUEVOS (blocks[0] = el primero añadido).
            $errors = array_map(static function (array $error) use ($offset): array {
                $error['path'] = (string) preg_replace_callback('/^blocks\[(\d+)\]/', static fn(array $m): string => 'blocks[' . ((int) $m[1] - $offset) . ']', $error['path']);
                return $error;
            }, $errors);
        }

        return [$saved, $errors, $warnings];
    }

    public function delete(string $nick, int $id): bool
    {
        return $this->find($nick, $id) !== null && $this->store->delete($id);
    }

    /** Nombre de archivo seguro a partir del título: «Propuesta Martínez» → propuesta-martinez.pdf. */
    public static function filename(string $title, string $extension): string
    {
        $ascii = strtr($title, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'à' => 'a', 'è' => 'e', 'ò' => 'o', 'ï' => 'i',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n', 'Ç' => 'c', 'À' => 'a', 'È' => 'e', 'Ò' => 'o', 'Ï' => 'i',
        ]);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
        $slug = rtrim(substr($slug, 0, 60), '-');
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'bin';

        return ($slug === '' ? 'documento' : $slug) . '.' . $extension;
    }

    /** Resumen sin spec para listados y respuestas. */
    public static function summary(array $document): array
    {
        return [
            'id' => (int) $document['id'],
            'kind' => (string) $document['kind'],
            'title' => (string) $document['title'],
            'status' => (string) $document['status'],
            'revision' => (int) $document['revision'],
            'version' => max(1, (int) ($document['version'] ?? 1)),
            'last_update' => $document['last_update'] ?? null,
            'blocks' => count($document['spec']['blocks'] ?? []),
        ];
    }

    /**
     * Artefacto `beply.artifact/v1` para el visor del chat. El informe de
     * auditoría NUNCA se abre solo (`open:false`): queda como tarjeta «Abrir»
     * en el mensaje. El documento del modo Documentos se abre en directo.
     * `version` fija la versión de ESTA tarjeta: el visor la abre aunque
     * después haya versiones nuevas.
     */
    public static function artifact(array $document): array
    {
        $id = (int) $document['id'];
        $kind = $document['kind'] === 'audit' ? 'audit' : 'document';

        return [
            'contract' => self::ARTIFACT_CONTRACT,
            'kind' => 'document',
            'id' => 'document:' . $id,
            'title' => mb_substr((string) $document['title'], 0, DocumentSpec::MAX_TITLE),
            'revision' => max(1, (int) $document['revision']),
            'version' => max(1, (int) ($document['version'] ?? 1)),
            'status' => $document['status'] === 'saved' ? 'saved' : 'draft',
            'variant' => $kind,
            'url' => 'PdfStudioChatDocument?id=' . $id . '&embed=1',
            'open' => $kind !== 'audit',
        ];
    }
}
