<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

/**
 * Datos del visor nativo del chat para una hoja de cálculo (y, más adelante,
 * una presentación): `PdfStudioChatDocument?id=N&format=json`.
 *
 * El chat NO usa iframes ni visores de terceros: pinta la tabla (o las
 * diapositivas) con sus propios componentes a partir de la especificación
 * normalizada y del tema de PDF Studio. Aquí sólo hay datos: nada de HTML, rutas
 * del servidor, nick ni empresa.
 */
final class OfficeViewData
{
    public const CONTRACT = 'beply.office-view/v1';

    public static function build(?array $document, array $branding): ?array
    {
        if ($document === null || false === in_array($document['kind'] ?? null, DocumentService::OFFICE_KINDS, true)) {
            return null;
        }

        return [
            'contract' => self::CONTRACT,
            'kind' => (string) $document['kind'],
            'id' => (int) $document['id'],
            'title' => (string) $document['title'],
            'version' => max(1, (int) ($document['version'] ?? 1)),
            'revision' => max(1, (int) ($document['revision'] ?? 1)),
            'formats' => DocumentService::exportFormats((string) $document['kind']),
            'spec' => $document['spec'],
            'theme' => OfficeTheme::fromBranding($branding),
        ];
    }
}
