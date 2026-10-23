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
    /** Documentos de texto (modo Documentos e informe de auditoría). */
    public const KINDS = ['document', 'audit'];
    /** Hojas de cálculo (y presentaciones) del chat: otra especificación, mismo almacén y versiones. */
    public const OFFICE_KINDS = ['spreadsheet'];
    public const ALL_KINDS = ['document', 'audit', 'spreadsheet'];
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
        $kind = in_array($kind, self::ALL_KINDS, true) ? $kind : null;

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
    public function save(string $nick, ?int $idempresa, $spec, string $kind = 'document', ?int $documentId = null, ?string $conversationId = null, bool $newVersion = true, ?string $docType = null): array
    {
        if (false === in_array($kind, self::ALL_KINDS, true)) {
            return [null, [['path' => 'kind', 'code' => 'invalid-value', 'message' => 'kind es ' . implode(', ', self::ALL_KINDS) . '.']], []];
        }
        if ($documentId !== null) {
            // Una hoja de cálculo nunca pasa a ser un documento de texto ni al revés.
            $existing = $this->find($nick, $documentId);
            if ($existing !== null && self::family((string) $existing['kind']) !== self::family($kind)) {
                return [null, [self::kindMismatch((string) $existing['kind'])], []];
            }
        }
        [$normalized, $errors] = self::validateSpec($kind, $spec);
        if ($normalized === null) {
            return [null, $errors, []];
        }
        $warnings = $normalized['_warnings'] ?? [];
        unset($normalized['_warnings']);
        if ($conversationId !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $conversationId) !== 1) {
            $conversationId = null;
        }
        $docType = DocumentStyle::normalizeType($docType);

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
            if ($docType !== null) {
                $document['doc_type'] = $docType;
            }
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
            'doc_type' => $docType,
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
        if (false === in_array($document['kind'], self::KINDS, true)) {
            return [null, [self::kindMismatch((string) $document['kind'])], []];
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

    /**
     * Cambios de estilo del tipo de documento: el personal del propietario, si
     * no el de su empresa y si no el global. Vacío si no hay ninguno.
     */
    public function styleFor(array $document): array
    {
        $style = $this->styleRowFor($document);

        return $style === null ? [] : $style['overrides'];
    }

    private function styleRowFor(array $document): ?array
    {
        $type = $document['doc_type'] ?? null;
        if (false === is_string($type) || $type === '') {
            return null;
        }
        $idempresa = isset($document['idempresa']) ? (int) $document['idempresa'] : null;

        return $this->store->findStyle((string) $document['nick'], null, $type)
            ?? ($idempresa !== null ? $this->store->findStyle(null, $idempresa, $type) : null)
            ?? $this->store->findStyle(null, null, $type);
    }

    public function listStyles(string $nick, ?int $idempresa): array
    {
        return array_map([self::class, 'styleSummary'], $this->store->listStyles($nick, $idempresa));
    }

    /**
     * Crea o cambia el estilo de un tipo de documento. `$companyWide`: para toda
     * la empresa (quien administra PDF Studio); si no, personal. Con
     * `$documentId`, ese documento pasa a ser de ese tipo y se vuelve a pintar
     * (sube la revisión, no crea versión: el texto no cambia).
     *
     * @return array{0: ?array, 1: ?array, 2: list<array{path: string, code: string, message: string}>}
     */
    public function saveStyle(string $nick, ?int $idempresa, bool $companyWide, $docType, $label, array $changes, ?int $documentId = null): array
    {
        $type = DocumentStyle::normalizeType($docType);
        if ($type === null) {
            return [null, null, [['path' => 'doc_type', 'code' => 'required', 'message' => 'Indica el tipo de documento (contrato, propuesta, carta…).']]];
        }
        $document = null;
        if ($documentId !== null) {
            $document = $this->find($nick, $documentId);
            if ($document === null) {
                return [null, null, [['path' => 'document_id', 'code' => 'document-not-found', 'message' => 'Ese documento no existe o no es tuyo.']]];
            }
        }
        [$overrides, $errors] = DocumentStyle::validate($changes);
        if ($errors !== []) {
            return [null, null, $errors];
        }
        $scopeNick = $companyWide ? null : $nick;
        $scopeCompany = $companyWide ? $idempresa : null;
        $existing = $this->store->findStyle($scopeNick, $scopeCompany, $type);
        if ($existing === null && count($this->store->listStyles($nick, $idempresa)) >= DocumentStyle::MAX_STYLES) {
            return [null, null, [['path' => 'doc_type', 'code' => 'too-many-styles', 'message' => 'Ya hay ' . DocumentStyle::MAX_STYLES . ' estilos de documentos; cambia uno existente.']]];
        }
        // Lo no indicado se conserva del estilo que ya tenía ese tipo.
        $merged = array_merge($existing['overrides'] ?? [], $overrides);
        $title = is_string($label) && trim($label) !== '' ? mb_substr(trim(DocumentSpec::text($label, false) ?? ''), 0, 80) : ($existing['label'] ?? ucfirst(str_replace('-', ' ', $type)));
        $id = $this->store->saveStyle(['nick' => $scopeNick, 'idempresa' => $scopeCompany, 'doc_type' => $type, 'label' => $title, 'overrides' => $merged]);
        if ($id === null) {
            return [null, null, [['path' => '', 'code' => 'save-failed', 'message' => 'No se pudo guardar el estilo.']]];
        }
        $style = $this->store->findStyle($scopeNick, $scopeCompany, $type);

        if ($document !== null) {
            $document['doc_type'] = $type;
            $document['revision'] = (int) $document['revision'] + 1;
            $document = $this->store->update($document) ? $this->store->find((int) $document['id']) : null;
            if ($document === null) {
                return [null, null, [['path' => '', 'code' => 'save-failed', 'message' => 'Se guardó el estilo pero no se pudo aplicar al documento.']]];
            }
        }

        return [self::styleSummary($style), $document, []];
    }

    public static function styleSummary(array $style): array
    {
        return [
            'doc_type' => (string) $style['doc_type'],
            'label' => (string) $style['label'],
            'scope' => $style['nick'] !== null ? 'personal' : ($style['idempresa'] !== null ? 'company' : 'global'),
            'changes' => $style['overrides'],
        ];
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
            'doc_type' => $document['doc_type'] ?? null,
            'last_update' => $document['last_update'] ?? null,
        ] + ($document['kind'] === 'spreadsheet'
            ? ['sheets' => count($document['spec']['sheets'] ?? []), 'rows' => array_sum(array_map(static fn(array $sheet): int => count($sheet['rows'] ?? []), $document['spec']['sheets'] ?? []))]
            : ['blocks' => count($document['spec']['blocks'] ?? [])]);
    }

    /** Especificación validada según el tipo: [normalizada con `_warnings` | null, errores]. */
    public static function validateSpec(string $kind, $spec): array
    {
        return $kind === 'spreadsheet' ? SpreadsheetSpec::validate($spec) : DocumentSpec::validate($spec);
    }

    /** Formatos de descarga de cada tipo (el primero es el principal). */
    public static function exportFormats(string $kind): array
    {
        return match ($kind) {
            'spreadsheet' => ['xlsx', 'csv'],
            default => ['pdf', 'docx'],
        };
    }

    /** Familia de un tipo: los documentos de texto (document/audit) o cada tipo ofimático. */
    private static function family(string $kind): string
    {
        return in_array($kind, self::KINDS, true) ? 'text' : $kind;
    }

    private static function kindMismatch(string $kind): array
    {
        $label = ['spreadsheet' => 'una hoja de cálculo', 'audit' => 'un informe de auditoría'][$kind] ?? 'un documento de texto';

        return ['path' => 'document_id', 'code' => 'document-kind-mismatch', 'message' => 'Ese número es ' . $label . ': usa su herramienta o crea uno nuevo sin document_id.'];
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
        if (in_array($document['kind'], self::OFFICE_KINDS, true)) {
            // Hoja de cálculo: el visor nativo del chat la pinta desde sus datos (`format=json`).
            return [
                'contract' => self::ARTIFACT_CONTRACT,
                'kind' => (string) $document['kind'],
                'id' => $document['kind'] . ':' . $id,
                'title' => mb_substr((string) $document['title'], 0, DocumentSpec::MAX_TITLE),
                'revision' => max(1, (int) $document['revision']),
                'version' => max(1, (int) ($document['version'] ?? 1)),
                'url' => 'PdfStudioChatDocument?id=' . $id . '&format=json',
                'open' => true,
            ];
        }
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
