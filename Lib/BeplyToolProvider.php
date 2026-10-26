<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentBranding;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentSpec;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentStore;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentStyle;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\ModelDocumentStore;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\PresentationPptxWriter;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\SpreadsheetCsvWriter;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\SpreadsheetSpec;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\SpreadsheetXlsxWriter;

/**
 * Tools del modo Documentos del chat (`beply.tools/v1`, docs/chat-documents.md).
 *
 * La puerta neutra /api/3/beply-tools de BeplyChatAI descubre esta clase,
 * resuelve el actor desde la clave espejo y exige la página `ChatAPI` (quien
 * puede usar el chat puede redactar sus propios documentos). Los documentos se
 * pintan con el estilo configurado en PDF Studio (diseño, logo, colores, fuente,
 * pie). Dominio `documents_canvas`: el catálogo genérico del ADK no las expone;
 * sólo las usa su toolset nativo (capacidad `documents_canvas_v1`, que el chat
 * anuncia únicamente con PDF Studio habilitado). Ninguna toca datos del ERP.
 *
 * Hojas de cálculo (`beplypdfstudio.spreadsheet_save`, dominio `office_canvas`,
 * capacidad `spreadsheet_canvas_v1`): el asistente las rellena con datos ya
 * consultados al ERP; se guardan versionadas como los documentos y, además, se
 * escribe el XLSX (o CSV) como archivo adjunto en esta misma petición de
 * /api/3/beply-tools: BeplyChatAI lo liga al usuario y lo archiva en «Documentos
 * del chat»; por WhatsApp sale como documento. Presentaciones
 * (`beplypdfstudio.presentation_save`, capacidad `presentation_canvas_v1`): igual,
 * con el PPTX como archivo (y PDF descargable desde el visor).
 */
final class BeplyToolProvider
{
    public const VERSION = '1.0.0';
    public const PERMISSION = 'ChatAPI';
    public const DOMAIN = 'documents_canvas';
    /** Hojas de cálculo (y presentaciones) del chat: toolset nativo propio del ADK. */
    public const OFFICE_DOMAIN = 'office_canvas';
    /** Quien entra aquí crea estilos de documentos para toda la empresa. */
    public const STYLE_ADMIN_PAGE = 'AdminBeplyPdf';

    /**
     * @var array{store?: callable, company?: callable, branding?: callable, render_branding?: callable, files?: callable}
     *      Sustitutos para tests. `files(bytes, filename, mime)` devuelve el `generated_file`.
     */
    public static array $factories = [];

    public static function beplyToolContract(): string
    {
        return 'beply.tools/v1';
    }

    public static function tools(): array
    {
        $readOnly = ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
        $policy = static fn(string $mode): array => [
            'executionMode' => $mode,
            'permissions' => [self::PERMISSION],
            'humanReviewRequired' => false,
        ];
        $documentId = ['type' => 'integer', 'minimum' => 1];

        return [
            [
                'name' => 'beplypdfstudio.document_catalog',
                'version' => self::VERSION,
                'description' => 'Formato beply.document/v1 (bloques, metadatos, marcado en línea y límites), un ejemplo y la marca que se aplicará (logo, color, fuente, papel). Úsala antes de redactar el primer documento.',
                'domain' => self::DOMAIN,
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false],
                'annotations' => $readOnly,
                'policy' => $policy('read_only'),
            ],
            [
                'name' => 'beplypdfstudio.document_list',
                'version' => self::VERSION,
                'description' => 'Documentos del usuario redactados en el chat (id, título, tipo document|audit, estado, revisión), más recientes primero.',
                'domain' => self::DOMAIN,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['kind' => ['type' => 'string', 'enum' => DocumentService::ALL_KINDS]],
                    'additionalProperties' => false,
                ],
                'annotations' => $readOnly,
                'policy' => $policy('read_only'),
            ],
            [
                'name' => 'beplypdfstudio.document_get',
                'version' => self::VERSION,
                'description' => 'Especificación completa de un documento del usuario, para editarlo.',
                'domain' => self::DOMAIN,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['document_id' => $documentId],
                    'required' => ['document_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => $readOnly,
                'policy' => $policy('read_only'),
            ],
            [
                'name' => 'beplypdfstudio.document_save',
                'version' => self::VERSION,
                'description' => 'Crea un documento (sin document_id) o reemplaza la especificación completa de uno existente, que pasa a ser su versión siguiente (la anterior se conserva y el usuario puede volver a ella). Valida beply.document/v1 y devuelve errores por ruta para corregir; no toca datos del ERP.',
                'domain' => self::DOMAIN,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'spec' => ['type' => 'object', 'description' => 'Especificación beply.document/v1 completa'],
                        'document_id' => $documentId,
                        'kind' => ['type' => 'string', 'enum' => DocumentService::KINDS],
                        'doc_type' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Tipo de documento (contrato, propuesta, carta, acta…): aplica su estilo si existe'],
                        'conversation_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$'],
                    ],
                    'required' => ['spec'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnly' => false, 'destructive' => false, 'idempotent' => false],
                'policy' => $policy('autonomous'),
            ],
            [
                'name' => 'beplypdfstudio.document_append',
                'version' => self::VERSION,
                'description' => 'Añade bloques al FINAL de un documento existente (documentos largos: redacta sección a sección sin reenviar lo ya escrito), dentro de su versión actual. Valida el documento completo y devuelve errores por ruta de los bloques nuevos.',
                'domain' => self::DOMAIN,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'document_id' => $documentId,
                        'blocks' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object'], 'description' => 'Bloques beply.document/v1 a añadir al final'],
                        'conversation_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$'],
                    ],
                    'required' => ['document_id', 'blocks'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnly' => false, 'destructive' => false, 'idempotent' => false],
                'policy' => $policy('autonomous'),
            ],
            [
                'name' => 'beplypdfstudio.document_style_list',
                'version' => self::VERSION,
                'description' => 'Estilos por tipo de documento (colores, fuente, tamaños, logo sobre el estilo de PDF Studio de la empresa) y las fuentes instaladas.',
                'domain' => self::DOMAIN,
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false],
                'annotations' => $readOnly,
                'policy' => $policy('read_only'),
            ],
            [
                'name' => 'beplypdfstudio.document_style_save',
                'version' => self::VERSION,
                'description' => 'Crea o cambia el estilo de un TIPO de documento (p. ej. «contrato») cuando el usuario pide otros colores, otra fuente u otro tamaño: se guarda en PDF Studio y se aplica a todos los documentos de ese tipo. Con document_id lo aplica también a ese documento. No cambia facturas ni otros formatos de impresión.',
                'domain' => self::DOMAIN,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'doc_type' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Tipo de documento: contrato, propuesta, carta, acta, memoria…'],
                        'label' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Nombre del estilo («Contratos azul corporativo»)'],
                        'color_primary' => ['type' => 'string', 'description' => '#RRGGBB títulos, líneas y cabeceras de tabla'],
                        'color_secondary' => ['type' => 'string', 'description' => '#RRGGBB acento (avisos, citas)'],
                        'color_tertiary' => ['type' => 'string', 'description' => '#RRGGBB fondo de filas alternas y notas'],
                        'color_text' => ['type' => 'string', 'description' => '#RRGGBB texto'],
                        'font_family' => ['type' => 'string', 'description' => 'Una de font_families de document_style_list'],
                        'font_size' => ['type' => 'integer', 'minimum' => DocumentStyle::FONT_SIZE[0], 'maximum' => DocumentStyle::FONT_SIZE[1]],
                        'title_font_size' => ['type' => 'integer', 'minimum' => DocumentStyle::TITLE_FONT_SIZE[0], 'maximum' => DocumentStyle::TITLE_FONT_SIZE[1]],
                        'logo_position' => ['type' => 'string', 'enum' => BeplyPdfConfig::POSICIONES],
                        'document_id' => $documentId,
                    ],
                    'required' => ['doc_type'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnly' => false, 'destructive' => false, 'idempotent' => true],
                'policy' => $policy('autonomous'),
            ],
            [
                'name' => 'beplypdfstudio.spreadsheet_save',
                'version' => self::VERSION,
                'description' => 'Crea (sin document_id) o reemplaza (con document_id, versión siguiente) una hoja de cálculo beply.spreadsheet/v1 con datos YA consultados al ERP: hojas, columnas tipadas (text, number, integer, currency, percent, date) con totales y filas. Se ve en el chat con el estilo de PDF Studio y se descarga en XLSX o CSV; el archivo queda guardado para el usuario. Devuelve errores por celda para corregir.',
                'domain' => self::OFFICE_DOMAIN,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'spec' => ['type' => 'object', 'description' => 'Especificación beply.spreadsheet/v1 completa'],
                        'document_id' => $documentId,
                        'doc_type' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Tipo (p. ej. «listado»): aplica su estilo de PDF Studio si existe'],
                        'file_format' => ['type' => 'string', 'enum' => ['xlsx', 'csv'], 'description' => 'Archivo que se guarda para el usuario (por defecto xlsx)'],
                        'conversation_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$'],
                    ],
                    'required' => ['spec'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnly' => false, 'destructive' => false, 'idempotent' => false],
                'policy' => $policy('autonomous'),
            ],
            [
                'name' => 'beplypdfstudio.presentation_save',
                'version' => self::VERSION,
                'description' => 'Crea (sin document_id) o reemplaza (con document_id, versión siguiente) una presentación beply.presentation/v1 con datos YA consultados al ERP: diapositivas de portada, sección, viñetas, tabla, gráfico (barras, columnas, líneas o tarta) y cifras clave. Se ve en el chat con el estilo de PDF Studio y se descarga en PPTX o PDF; el PPTX queda guardado para el usuario.',
                'domain' => self::OFFICE_DOMAIN,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'spec' => ['type' => 'object', 'description' => 'Especificación beply.presentation/v1 completa'],
                        'document_id' => $documentId,
                        'doc_type' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Tipo (p. ej. «presentación»): aplica su estilo de PDF Studio si existe'],
                        'conversation_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$'],
                    ],
                    'required' => ['spec'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnly' => false, 'destructive' => false, 'idempotent' => false],
                'policy' => $policy('autonomous'),
            ],
        ];
    }

    public static function execute(string $tool, array $arguments, object $actor, array $context = []): array
    {
        $nick = (string) $actor->nick();
        if ($nick === '') {
            return self::failure('actor', 'actor-required', 'No hay usuario.');
        }

        switch ($tool) {
            case 'beplypdfstudio.document_catalog':
                return self::catalog($nick);

            case 'beplypdfstudio.document_list':
                $kind = $arguments['kind'] ?? null;
                return [
                    'ok' => true,
                    'documents' => array_map([DocumentService::class, 'summary'], self::service()->list($nick, is_string($kind) ? $kind : null, 20)),
                    'mutations_committed' => false,
                ];

            case 'beplypdfstudio.document_get':
                $document = self::service()->find($nick, (int) ($arguments['document_id'] ?? 0));
                if ($document === null) {
                    return self::failure('document_id', 'document-not-found', 'Ese documento no existe o no es tuyo.');
                }
                return [
                    'ok' => true,
                    'document' => DocumentService::summary($document) + ['spec' => $document['spec']],
                    'artifact' => DocumentService::artifact($document),
                    'mutations_committed' => false,
                ];

            case 'beplypdfstudio.document_save':
                return self::save($arguments, $nick);

            case 'beplypdfstudio.spreadsheet_save':
                return self::saveOffice('spreadsheet', $arguments, $nick);

            case 'beplypdfstudio.presentation_save':
                return self::saveOffice('presentation', $arguments, $nick);

            case 'beplypdfstudio.document_style_list':
                return [
                    'ok' => true,
                    'styles' => self::service()->listStyles($nick, self::company($nick)),
                    'font_families' => DocumentStyle::families(),
                    'scope' => self::companyWide($actor) ? 'company' : 'personal',
                    'mutations_committed' => false,
                ];

            case 'beplypdfstudio.document_style_save':
                $documentId = isset($arguments['document_id']) ? (int) $arguments['document_id'] : null;
                [$style, $document, $errors] = self::service()->saveStyle(
                    $nick,
                    self::company($nick),
                    self::companyWide($actor),
                    $arguments['doc_type'] ?? null,
                    $arguments['label'] ?? null,
                    array_intersect_key($arguments, array_flip(['color_primary', 'color_secondary', 'color_tertiary', 'color_text', 'font_family', 'font_size', 'title_font_size', 'logo_position'])),
                    $documentId
                );
                if ($style === null) {
                    return ['ok' => false, 'errors' => $errors, 'mutations_committed' => false];
                }
                return [
                    'ok' => true,
                    'style' => $style,
                    'mutations_committed' => true,
                ] + ($document !== null ? ['document' => DocumentService::summary($document), 'artifact' => DocumentService::artifact($document)] : []);

            case 'beplypdfstudio.document_append':
                $conversationId = isset($arguments['conversation_id']) ? (string) $arguments['conversation_id'] : null;
                [$document, $errors, $warnings] = self::service()->append($nick, (int) ($arguments['document_id'] ?? 0), $arguments['blocks'] ?? null, $conversationId);
                if ($document === null) {
                    return ['ok' => false, 'errors' => $errors, 'mutations_committed' => false];
                }
                return [
                    'ok' => true,
                    'document' => DocumentService::summary($document),
                    'artifact' => DocumentService::artifact($document),
                    'warnings' => $warnings,
                    'mutations_committed' => true,
                ];
        }

        return self::failure('tool', 'unknown-tool', 'Tool desconocida.');
    }

    private static function save(array $arguments, string $nick): array
    {
        $kind = isset($arguments['kind']) && is_string($arguments['kind']) ? $arguments['kind'] : 'document';
        $documentId = isset($arguments['document_id']) ? (int) $arguments['document_id'] : null;
        $conversationId = isset($arguments['conversation_id']) ? (string) $arguments['conversation_id'] : null;

        $docType = isset($arguments['doc_type']) && is_string($arguments['doc_type']) ? $arguments['doc_type'] : null;
        [$document, $errors, $warnings] = self::service()->save($nick, self::company($nick), $arguments['spec'] ?? null, $kind, $documentId, $conversationId, true, $docType);
        if ($document === null) {
            return ['ok' => false, 'errors' => $errors, 'mutations_committed' => false];
        }

        return [
            'ok' => true,
            'document' => DocumentService::summary($document),
            'artifact' => DocumentService::artifact($document),
            'warnings' => $warnings,
            'mutations_committed' => true,
        ];
    }

    /** Hoja de cálculo o presentación: guarda la especificación (versionada) y escribe su archivo para el usuario. */
    private static function saveOffice(string $kind, array $arguments, string $nick): array
    {
        $documentId = isset($arguments['document_id']) ? (int) $arguments['document_id'] : null;
        $conversationId = isset($arguments['conversation_id']) ? (string) $arguments['conversation_id'] : null;
        $docType = isset($arguments['doc_type']) && is_string($arguments['doc_type']) ? $arguments['doc_type'] : null;
        $format = ($arguments['file_format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
        $service = self::service();
        [$document, $errors, $warnings] = $service->save($nick, self::company($nick), $arguments['spec'] ?? null, $kind, $documentId, $conversationId, true, $docType);
        if ($document === null) {
            return ['ok' => false, 'errors' => $errors, 'mutations_committed' => false];
        }

        $file = null;
        try {
            $title = (string) $document['title'];
            $branding = DocumentStyle::applyToBranding(self::renderBranding(self::company($nick)), $service->styleFor($document));
            if ($kind === 'presentation') {
                $format = 'pptx';
                $file = self::storeFile(PresentationPptxWriter::render($document['spec'], $branding), DocumentService::filename($title, 'pptx'), PresentationPptxWriter::MIME);
            } elseif ($format === 'csv') {
                $file = self::storeFile(SpreadsheetCsvWriter::render($document['spec'], 0), DocumentService::filename($title, 'csv'), SpreadsheetCsvWriter::MIME);
            } else {
                $file = self::storeFile(SpreadsheetXlsxWriter::render($document['spec'], $branding), DocumentService::filename($title, 'xlsx'), SpreadsheetXlsxWriter::MIME);
            }
        } catch (\Throwable $e) {
            $file = null;
        }
        if ($file === null) {
            $warnings[] = ($kind === 'presentation' ? 'La presentación' : 'La hoja') . ' se guardó, pero no se pudo crear el archivo ' . strtoupper($format) . '; se puede descargar desde el visor.';
        }

        return [
            'ok' => true,
            'document' => DocumentService::summary($document),
            'artifact' => DocumentService::artifact($document),
            'generated_file' => $file,
            'warnings' => $warnings,
            'mutations_committed' => true,
        ];
    }

    /** Marca completa (logo, colores, fuente) para escribir el archivo. */
    public static function renderBranding(?int $idempresa): array
    {
        return isset(self::$factories['render_branding'])
            ? (self::$factories['render_branding'])($idempresa)
            : DocumentBranding::resolve($idempresa);
    }

    /**
     * Guarda los bytes como archivo adjunto del ERP y devuelve su `generated_file`
     * ({idfile, filename, mimetype, path, size}) o null. Al crearse dentro de la
     * petición de /api/3/beply-tools con la clave espejo, BeplyChatAI lo liga al
     * usuario del chat y lo archiva en «Documentos del chat» de Biblioteca.
     */
    private static function storeFile(string $bytes, string $filename, string $mime): ?array
    {
        if (isset(self::$factories['files'])) {
            return (self::$factories['files'])($bytes, $filename, $mime);
        }
        $folder = \FacturaScripts\Core\Tools::folder('MyFiles');
        $temp = 'chat-office-' . bin2hex(random_bytes(6)) . '-' . $filename;
        if (false === file_put_contents($folder . '/' . $temp, $bytes)) {
            return null;
        }
        $class = class_exists('\\FacturaScripts\\Dinamic\\Model\\AttachedFile')
            ? '\\FacturaScripts\\Dinamic\\Model\\AttachedFile'
            : '\\FacturaScripts\\Core\\Model\\AttachedFile';
        $attached = new $class();
        $attached->path = $temp;
        if (false === $attached->save()) {
            @unlink($folder . '/' . $temp);
            return null;
        }
        // Nombre legible y tipo exacto (finfo puede ver un XLSX como application/zip).
        $attached->filename = $filename;
        $attached->mimetype = explode(';', $mime)[0];
        $attached->save();

        return [
            'idfile' => (int) $attached->idfile,
            'filename' => $filename,
            'mimetype' => explode(';', $mime)[0],
            'path' => (string) $attached->path,
            'size' => (int) $attached->size,
        ];
    }

    private static function catalog(string $nick): array
    {
        $branding = isset(self::$factories['branding'])
            ? (self::$factories['branding'])($nick)
            : DocumentBranding::describe(DocumentBranding::resolve(self::company($nick)));

        return [
            'ok' => true,
            'schema' => DocumentSpec::CONTRACT,
            'kinds' => ['document' => 'carta, contrato, propuesta, memoria, acta, informe... (se abre en el panel)', 'audit' => 'informe de auditoría (queda como tarjeta en el mensaje)'],
            'meta_fields' => [
                'subtitle' => 'texto bajo el título',
                'date' => 'AAAA-MM-DD (se escribe «16 de octubre de 2026») o texto libre',
                'place' => 'lugar de la fecha (Valencia)',
                'reference' => 'referencia (Ref.:)',
                'subject' => 'asunto (Asunto:)',
                'recipient' => '{name, lines[≤' . DocumentSpec::MAX_RECIPIENT_LINES . ']} destinatario de una carta',
                'show_company' => 'false oculta los datos de la empresa del membrete (el logo se mantiene)',
                'show_title' => 'false oculta el título grande (p. ej. en una carta con asunto)',
            ],
            'block_types' => [
                ['type' => 'heading', 'fields' => 'text, level 1|2|3'],
                ['type' => 'paragraph', 'fields' => 'text (≤' . DocumentSpec::MAX_TEXT . '), align left|center|right|justify'],
                ['type' => 'list', 'fields' => 'style bullet|number, items[≤' . DocumentSpec::MAX_LIST_ITEMS . ']'],
                ['type' => 'table', 'fields' => 'header[≤' . DocumentSpec::MAX_TABLE_COLUMNS . '], rows[[...]] (≤' . DocumentSpec::MAX_TABLE_ROWS . ' filas), caption'],
                ['type' => 'quote', 'fields' => 'text'],
                ['type' => 'note', 'fields' => 'text, tone info|warning|success, title'],
                ['type' => 'signature', 'fields' => 'name, role, place_date'],
                ['type' => 'spacer', 'fields' => 'size sm|md|lg'],
                ['type' => 'page_break', 'fields' => '(sin campos)'],
            ],
            'inline_markup' => '**negrita** y *cursiva* dentro de text, items y celdas; saltos de línea con \\n. Nada de HTML, enlaces ni estilos: el diseño lo pone el módulo.',
            'limits' => ['blocks' => DocumentSpec::MAX_BLOCKS, 'title' => DocumentSpec::MAX_TITLE, 'total_chars' => DocumentSpec::MAX_TOTAL_CHARS],
            'long_documents' => 'Máximo 10 páginas A4 (≈ ' . DocumentSpec::MAX_TOTAL_CHARS . ' caracteres de texto). Para documentos largos crea el documento con document_save (título, apertura y primeras secciones) y añade el resto página a página con document_append (una o dos páginas por llamada). Una página A4 son unas 450-550 palabras de texto corrido; secciones completas, no esquemas.',
            'design' => self::design($nick),
            'styles' => 'Indica doc_type en document_save (contrato, propuesta, carta…). Si el usuario pide otros colores, otra fuente u otro tamaño, guarda un estilo para ese tipo con document_style_save (con document_id para aplicarlo ya) en vez de cambiar el texto: el diseño siempre lo pone PDF Studio.',
            'branding' => $branding,
            'example' => [
                'schema' => DocumentSpec::CONTRACT,
                'title' => 'Propuesta de mantenimiento anual',
                'meta' => [
                    'date' => date('Y-m-d'),
                    'place' => 'Valencia',
                    'recipient' => ['name' => 'Ferretería Martínez S.L.', 'lines' => ['C/ Mayor 12', '46001 Valencia']],
                    'subject' => 'Propuesta de mantenimiento',
                ],
                'blocks' => [
                    ['type' => 'paragraph', 'text' => 'Estimado Sr. Martínez:'],
                    ['type' => 'paragraph', 'text' => 'Le presentamos nuestra propuesta de **mantenimiento anual**.', 'align' => 'justify'],
                    ['type' => 'heading', 'text' => 'Condiciones', 'level' => 1],
                    ['type' => 'list', 'style' => 'bullet', 'items' => ['Revisión trimestral', 'Atención en 24 h']],
                    ['type' => 'table', 'header' => ['Concepto', 'Importe'], 'rows' => [['Cuota anual', '1.200,00 €']]],
                    ['type' => 'signature', 'name' => 'Ana López', 'role' => 'Dirección comercial'],
                ],
            ],
            'mutations_committed' => false,
        ];
    }

    /** Diseño de PDF Studio que se aplicará (nombre del estilo y del diseño). */
    private static function design(string $nick): array
    {
        try {
            $service = new BeplyPdfRenderService();
            $style = $service->resolveStyle(null, self::company($nick));
            $config = $service->resolveConfig(null, self::company($nick));
        } catch (\Throwable) {
            return ['style' => null, 'layout' => null];
        }

        return [
            'style' => $style !== null ? (string) ($style->nombre ?? '') : null,
            'layout' => $config !== null ? (string) $config->diseno : null,
        ];
    }

    /** Estilos para toda la empresa: administradores y quien gestiona PDF Studio. */
    private static function companyWide(object $actor): bool
    {
        if (method_exists($actor, 'isAdmin') && $actor->isAdmin()) {
            return true;
        }

        return method_exists($actor, 'can') && $actor->can(self::STYLE_ADMIN_PAGE);
    }

    private static function failure(string $path, string $code, string $message): array
    {
        return ['ok' => false, 'errors' => [['path' => $path, 'code' => $code, 'message' => $message]], 'mutations_committed' => false];
    }

    public static function service(): DocumentService
    {
        $store = isset(self::$factories['store']) ? (self::$factories['store'])() : null;

        return new DocumentService($store instanceof DocumentStore ? $store : new ModelDocumentStore());
    }

    /** Empresa del usuario (o la predeterminada) para la marca del documento. */
    public static function company(string $nick): ?int
    {
        if (isset(self::$factories['company'])) {
            return (self::$factories['company'])($nick);
        }
        $user = new \FacturaScripts\Dinamic\Model\User();
        if ($user->load($nick) && false === empty($user->idempresa)) {
            return (int) $user->idempresa;
        }
        $default = \FacturaScripts\Core\Tools::settings('default', 'idempresa');

        return empty($default) ? null : (int) $default;
    }
}
