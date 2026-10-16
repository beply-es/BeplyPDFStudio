<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;

/**
 * Documento redactado en el chat (modo Documentos) o informe de
 * auditoría. Sólo el propietario (`nick`) lo ve: el proveedor de tools y el
 * controlador PdfStudioChatDocument pasan siempre por Lib/ChatDocument/DocumentService.
 */
class BeplyPdfChatDocument extends ModelClass
{
    use ModelTrait;

    public const KIND_DOCUMENT = 'document';
    public const KIND_AUDIT = 'audit';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SAVED = 'saved';

    /** @var string|null */
    public $conversation_id;

    /** @var string */
    public $creation_date;

    /** @var int */
    public $id;

    /** @var int|null */
    public $idempresa;

    /** @var string */
    public $kind;

    /** @var string|null */
    public $last_update;

    /** @var string */
    public $nick;

    /** @var int */
    public $revision;

    /** @var int Versión vigente («Versión N» del visor); las anteriores, en BeplyPdfChatDocumentVersion. */
    public $version;

    /** @var string */
    public $spec;

    /** @var string */
    public $status;

    /** @var string */
    public $title;

    public function clear(): void
    {
        parent::clear();
        $this->kind = self::KIND_DOCUMENT;
        $this->revision = 1;
        $this->version = 1;
        $this->status = self::STATUS_DRAFT;
    }

    public function install(): string
    {
        new User();
        return parent::install();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'beply_pdf_chat_documents';
    }

    public function test(): bool
    {
        $this->creation_date = $this->creation_date ?? Tools::dateTime();
        $this->title = trim((string) $this->title);
        if ($this->title === ''
            || empty($this->nick)
            || false === in_array($this->kind, [self::KIND_DOCUMENT, self::KIND_AUDIT], true)
            || false === in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SAVED], true)) {
            return false;
        }

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        return 'PdfStudioChatDocument?id=' . (int) $this->id;
    }
}
