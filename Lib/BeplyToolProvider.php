<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentBranding;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentSpec;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentStore;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\ModelDocumentStore;

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
 */
final class BeplyToolProvider
{
    public const VERSION = '1.0.0';
    public const PERMISSION = 'ChatAPI';
    public const DOMAIN = 'documents_canvas';

    /** @var array{store?: callable, company?: callable, branding?: callable} Sustitutos para tests. */
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
                    'properties' => ['kind' => ['type' => 'string', 'enum' => DocumentService::KINDS]],
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
                'description' => 'Crea un documento (sin document_id) o reemplaza la especificación completa de uno existente. Valida beply.document/v1 y devuelve errores por ruta para corregir; no toca datos del ERP.',
                'domain' => self::DOMAIN,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'spec' => ['type' => 'object', 'description' => 'Especificación beply.document/v1 completa'],
                        'document_id' => $documentId,
                        'kind' => ['type' => 'string', 'enum' => DocumentService::KINDS],
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
                'description' => 'Añade bloques al FINAL de un documento existente (documentos largos: redacta sección a sección sin reenviar lo ya escrito). Valida el documento completo y devuelve errores por ruta de los bloques nuevos.',
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

        [$document, $errors, $warnings] = self::service()->save($nick, self::company($nick), $arguments['spec'] ?? null, $kind, $documentId, $conversationId);
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
            'long_documents' => 'Para documentos largos (5-10 páginas o más) crea el documento con document_save (título, apertura y primeras secciones) y añade cada sección siguiente con document_append. Una página A4 son unos 450-550 palabras de texto corrido; redacta con la extensión pedida, con secciones completas (no esquemas).',
            'design' => self::design($nick),
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
