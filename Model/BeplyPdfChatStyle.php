<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;

/**
 * Estilo por tipo de documento del chat (Lib/ChatDocument/DocumentStyle):
 * cambios sobre el estilo de PDF Studio de la empresa. No interviene en
 * facturas ni en otros formatos de impresión.
 */
class BeplyPdfChatStyle extends ModelClass
{
    use ModelTrait;

    /** @var string */
    public $creation_date;

    /** @var string */
    public $doc_type;

    /** @var int */
    public $id;

    /** @var int|null */
    public $idempresa;

    /** @var string */
    public $label;

    /** @var string|null */
    public $last_update;

    /** @var string|null */
    public $nick;

    /** @var string JSON */
    public $overrides;

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
        return 'beply_pdf_chat_styles';
    }

    public function test(): bool
    {
        $this->creation_date = $this->creation_date ?? Tools::dateTime();
        $this->label = trim((string) $this->label);
        if ($this->label === '' || preg_match('/^[a-z0-9-]{2,40}$/', (string) $this->doc_type) !== 1) {
            return false;
        }

        return parent::test();
    }
}
