<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

/**
 * El documento se contradice a si mismo y no debe imprimirse.
 *
 * Se distingue de cualquier otro fallo de render a proposito: el resto de
 * excepciones degradan al motor del core, y esa degradacion volveria a emitir el
 * documento falso. Esta NO se captura.
 */
final class BeplyPdfInconsistentDocumentException extends \RuntimeException
{
}
