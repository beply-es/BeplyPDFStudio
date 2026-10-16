<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\BeplyPdfChatDocument;

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

    private static function fill(BeplyPdfChatDocument $model, array $document): void
    {
        $model->nick = $document['nick'];
        $model->idempresa = $document['idempresa'] ?? null;
        $model->kind = $document['kind'];
        $model->title = $document['title'];
        $model->status = $document['status'];
        $model->revision = (int) $document['revision'];
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
            'conversation_id' => $model->conversation_id,
            'creation_date' => (string) $model->creation_date,
            'last_update' => (string) ($model->last_update ?? $model->creation_date),
        ];
    }
}
