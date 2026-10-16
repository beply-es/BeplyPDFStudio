<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\BeplyPdfChatDocument;
use FacturaScripts\Dinamic\Model\BeplyPdfChatDocumentVersion;
use FacturaScripts\Dinamic\Model\BeplyPdfChatStyle;

/** DocumentStore sobre el modelo BeplyPdfChatDocument (tabla beply_pdf_chat_documents). */
final class ModelDocumentStore implements DocumentStore
{
    public function find(int $id): ?array
    {
        $model = new BeplyPdfChatDocument();
        return $model->load($id) ? self::toArray($model) : null;
    }

    public function listByNick(string $nick, ?string $kind = null, int $limit = 50): array
    {
        $where = [Where::eq('nick', $nick)];
        if ($kind !== null) {
            $where[] = Where::eq('kind', $kind);
        }
        $rows = [];
        foreach (BeplyPdfChatDocument::all($where, ['last_update' => 'DESC', 'id' => 'DESC'], 0, $limit) as $model) {
            $rows[] = self::toArray($model);
        }

        return $rows;
    }

    public function insert(array $document): ?int
    {
        $model = new BeplyPdfChatDocument();
        self::fill($model, $document);
        $model->creation_date = Tools::dateTime();
        $model->last_update = $model->creation_date;

        return $model->save() ? (int) $model->id : null;
    }

    public function update(array $document): bool
    {
        $model = new BeplyPdfChatDocument();
        if (false === $model->load((int) $document['id'])) {
            return false;
        }
        self::fill($model, $document);
        $model->last_update = Tools::dateTime();

        return $model->save();
    }

    public function delete(int $id): bool
    {
        $model = new BeplyPdfChatDocument();
        return $model->load($id) && $model->delete();
    }

    public function insertVersion(int $documentId, int $version, string $title, array $spec): bool
    {
        $model = new BeplyPdfChatDocumentVersion();
        $model->document_id = $documentId;
        $model->version = $version;
        $model->title = $title;
        $model->spec = (string) json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $model->creation_date = Tools::dateTime();

        return $model->save();
    }

    public function findVersion(int $documentId, int $version): ?array
    {
        $model = new BeplyPdfChatDocumentVersion();
        if (false === $model->loadWhere([Where::eq('document_id', $documentId), Where::eq('version', $version)])) {
            return null;
        }
        $spec = json_decode((string) $model->spec, true);

        return [
            'version' => (int) $model->version,
            'title' => (string) $model->title,
            'spec' => is_array($spec) ? $spec : [],
            'creation_date' => (string) $model->creation_date,
        ];
    }

    public function findStyle(?string $nick, ?int $idempresa, string $docType): ?array
    {
        $model = new BeplyPdfChatStyle();
        $where = [
            Where::eq('doc_type', $docType),
            $nick === null ? Where::isNull('nick') : Where::eq('nick', $nick),
            $idempresa === null || $nick !== null ? Where::isNull('idempresa') : Where::eq('idempresa', $idempresa),
        ];

        return $model->loadWhere($where) ? self::styleToArray($model) : null;
    }

    public function saveStyle(array $style): ?int
    {
        $nick = $style['nick'] ?? null;
        $idempresa = $nick === null ? ($style['idempresa'] ?? null) : null;
        $existing = $this->findStyle($nick, $idempresa, (string) $style['doc_type']);
        $model = new BeplyPdfChatStyle();
        if ($existing !== null) {
            $model->load((int) $existing['id']);
        } else {
            $model->creation_date = Tools::dateTime();
        }
        $model->nick = $nick;
        $model->idempresa = $idempresa;
        $model->doc_type = (string) $style['doc_type'];
        $model->label = (string) $style['label'];
        $model->overrides = (string) json_encode($style['overrides'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $model->last_update = Tools::dateTime();

        return $model->save() ? (int) $model->id : null;
    }

    public function listStyles(string $nick, ?int $idempresa): array
    {
        $rows = [];
        foreach (BeplyPdfChatStyle::all([], ['doc_type' => 'ASC', 'id' => 'ASC'], 0, 0) as $model) {
            $mine = $model->nick === $nick;
            $shared = $model->nick === null && ($model->idempresa === null || (int) $model->idempresa === $idempresa);
            if ($mine || $shared) {
                $rows[] = self::styleToArray($model);
            }
        }

        return $rows;
    }

    private static function styleToArray(BeplyPdfChatStyle $model): array
    {
        $overrides = json_decode((string) $model->overrides, true);

        return [
            'id' => (int) $model->id,
            'nick' => $model->nick === null ? null : (string) $model->nick,
            'idempresa' => $model->idempresa === null ? null : (int) $model->idempresa,
            'doc_type' => (string) $model->doc_type,
            'label' => (string) $model->label,
            'overrides' => is_array($overrides) ? $overrides : [],
            'last_update' => (string) ($model->last_update ?? $model->creation_date),
        ];
    }

    private static function fill(BeplyPdfChatDocument $model, array $document): void
    {
        $model->nick = $document['nick'];
        $model->idempresa = $document['idempresa'] ?? null;
        $model->kind = $document['kind'];
        $model->title = $document['title'];
        $model->status = $document['status'];
        $model->revision = (int) $document['revision'];
        $model->version = max(1, (int) ($document['version'] ?? 1));
        $model->doc_type = $document['doc_type'] ?? null;
        $model->conversation_id = $document['conversation_id'] ?? null;
        $model->spec = (string) json_encode($document['spec'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function toArray(BeplyPdfChatDocument $model): array
    {
        $spec = json_decode((string) $model->spec, true);

        return [
            'id' => (int) $model->id,
            'nick' => (string) $model->nick,
            'idempresa' => $model->idempresa === null ? null : (int) $model->idempresa,
            'kind' => (string) $model->kind,
            'title' => (string) $model->title,
            'status' => (string) $model->status,
            'spec' => is_array($spec) ? $spec : [],
            'revision' => (int) $model->revision,
            'version' => max(1, (int) $model->version),
            'doc_type' => $model->doc_type === null || $model->doc_type === '' ? null : (string) $model->doc_type,
            'conversation_id' => $model->conversation_id,
            'creation_date' => (string) $model->creation_date,
            'last_update' => (string) ($model->last_update ?? $model->creation_date),
        ];
    }
}
