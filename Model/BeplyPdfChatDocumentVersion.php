<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

/**
 * Versión anterior de un documento del chat. Sólo se lee a través de
 * Lib/ChatDocument/DocumentService (regla de propietario del documento).
 */
class BeplyPdfChatDocumentVersion extends ModelClass
{
    use ModelTrait;

    /** @var string */
    public $creation_date;

    /** @var int */
    public $document_id;

    /** @var int */
    public $id;

    /** @var string */
    public $spec;

    /** @var string */
    public $title;

    /** @var int */
    public $version;

    public function install(): string
    {
        new BeplyPdfChatDocument();
        return parent::install();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'beply_pdf_chat_document_versions';
    }

    public function test(): bool
    {
        $this->creation_date = $this->creation_date ?? Tools::dateTime();
        $this->title = trim((string) $this->title);
        if ($this->title === '' || (int) $this->document_id < 1 || (int) $this->version < 1) {
            return false;
        }

        return parent::test();
    }
}
