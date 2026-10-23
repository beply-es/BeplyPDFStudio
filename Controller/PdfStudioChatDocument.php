<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyToolProvider;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentBranding;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentDocxWriter;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentStyle;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\OfficeViewData;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\PresentationPptxWriter;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\SpreadsheetCsvWriter;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\SpreadsheetXlsxWriter;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use Throwable;

/**
 * Un documento del chat (modo Documentos o informe de auditoría), pintado con el
 * estilo de PDF Studio de su empresa (o el global) y, si su tipo («contrato»…)
 * tiene estilo propio (BeplyPdfChatStyle), con esos colores y esa fuente:
 *
 * | Petición | Respuesta |
 * |---|---|
 * | `GET ?id=N` / `?id=N&embed=1` | El PDF en línea (vista previa del panel del chat, con sus páginas) |
 * | `GET ?id=N&format=pdf` | El PDF para descargar |
 * | `GET ?id=N&format=docx` | Word con los colores, la fuente y el logo del estilo |
 * | `…&version=V` | La versión V del documento (las tarjetas antiguas del chat y el selector «Versión») |
 * | `GET ?id=N&format=json` | Hoja de cálculo: especificación + tema para el visor NATIVO del chat (`beply.office-view/v1`) |
 * | `GET ?id=N&format=xlsx` / `&format=csv[&sheet=K]` | Hoja de cálculo en Excel / CSV (Excel en español) |
 * | `GET ?id=N&format=pptx` / `&format=pdf` | Presentación en PowerPoint / PDF (16:9, WeasyPrint) |
 * | `GET ?id=N&format=logo` | El logo del estilo (para el visor), o 404 |
 *
 * Un formato que no corresponde al tipo (p. ej. `xlsx` de una carta) responde 404.
 * Sólo el propietario: un documento ajeno responde 404 igual que uno
 * inexistente. Entra quien puede usar el chat (página `ChatAPI`) aunque su rol
 * no tenga esta página.
 */
class PdfStudioChatDocument extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'Documento';
        $data['icon'] = 'fas fa-file-lines';
        $data['showonmenu'] = false;

        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        // Mismo permiso que el chat: los documentos son del propio usuario.
        if (false === $permissions->allowAccess) {
            $permissions = new ControllerPermissions($user, 'ChatAPI');
        }
        parent::privateCore($response, $user, $permissions);
        $this->setTemplate(false);

        $this->response->headers->set('Cache-Control', 'no-store');
        $this->response->headers->set('X-Content-Type-Options', 'nosniff');
        $this->response->headers->set('Referrer-Policy', 'same-origin');

        $id = (int) $this->request->query('id', '0');
        $version = (string) $this->request->query('version', '');
        $version = preg_match('/^[1-9]\d{0,3}$/', $version) === 1 ? (int) $version : null;
        $document = BeplyToolProvider::service()->findVersion((string) $this->user->nick, $id, $version);
        if ($document === null) {
            $this->response->setHttpCode(404);
            $this->response->headers->set('Content-Type', 'text/plain; charset=utf-8');
            $this->response->setContent('Este documento (o esa versión) no existe o no es tuyo.');
            return;
        }

        $format = (string) $this->request->query('format', '');
        $suffix = $version !== null ? ' v' . $version : '';
        $idempresa = isset($document['idempresa']) ? (int) $document['idempresa'] : null;
        $styleChanges = BeplyToolProvider::service()->styleFor($document);
        $office = in_array($document['kind'], DocumentService::OFFICE_KINDS, true);
        if ($format === 'logo' || $office || in_array($format, ['json', 'xlsx', 'csv', 'pptx'], true)) {
            $this->office($document, $format, $suffix, DocumentStyle::applyToBranding(DocumentBranding::resolve($idempresa), $styleChanges), $idempresa, $styleChanges);
            return;
        }
        try {
            if ($format === 'docx') {
                $bytes = DocumentDocxWriter::render($document['spec'], DocumentStyle::applyToBranding(DocumentBranding::resolve($idempresa), $styleChanges));
                $this->send($bytes, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', DocumentService::filename((string) $document['title'] . $suffix, 'docx'), 'attachment');
                return;
            }
            $config = DocumentStyle::applyToConfig((new BeplyPdfRenderService())->resolveConfig(null, $idempresa) ?? new BeplyPdfConfig(), $styleChanges);
            $bytes = (new BeplyHtmlRenderService())->renderChatDocument($config, $document['spec'], $idempresa);
        } catch (Throwable $e) {
            Tools::log()->error('beplypdf-chat-document-export-failed: ' . get_class($e));
            $bytes = '';
        }

        if ($bytes === '') {
            $this->response->setHttpCode(500);
            $this->response->headers->set('Content-Type', 'text/plain; charset=utf-8');
            $this->response->setContent('No se pudo generar el documento.');
            return;
        }

        $disposition = $format === 'pdf' ? 'attachment' : 'inline';
        $this->send($bytes, 'application/pdf', DocumentService::filename((string) $document['title'] . $suffix, 'pdf'), $disposition);
    }

    /** Hojas de cálculo y presentaciones: datos del visor nativo, XLSX, CSV, PPTX, PDF y logo. */
    private function office(array $document, string $format, string $suffix, array $branding, ?int $idempresa, array $styleChanges): void
    {
        $kind = (string) $document['kind'];
        if ($format === 'logo') {
            $path = $branding['logo']['path'] ?? null;
            if (is_string($path) && DocumentBranding::isLogo(['path' => $path])) {
                $this->response->headers->set('Cache-Control', 'private, max-age=300');
                $this->send((string) file_get_contents($path), (string) DocumentBranding::imageMime($path), 'logo', 'inline');
                return;
            }
            $this->notFound('Este estilo no tiene logo.');
            return;
        }
        if ($format === '' || $format === 'json') {
            $view = OfficeViewData::build($document, $branding);
            if ($view === null) {
                $this->notFound('Formato no disponible para este documento.');
                return;
            }
            $this->response->headers->set('Content-Type', 'application/json; charset=utf-8');
            $this->response->setContent((string) json_encode($view, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            return;
        }
        if (false === in_array($format, DocumentService::exportFormats($kind), true)) {
            $this->notFound('Formato no disponible para este documento.');
            return;
        }
        try {
            $title = (string) $document['title'] . $suffix;
            if ($kind === 'presentation') {
                if ($format === 'pptx') {
                    $this->send(PresentationPptxWriter::render($document['spec'], $branding), PresentationPptxWriter::MIME, DocumentService::filename($title, 'pptx'), 'attachment');
                    return;
                }
                $config = DocumentStyle::applyToConfig((new BeplyPdfRenderService())->resolveConfig(null, $idempresa) ?? new BeplyPdfConfig(), $styleChanges);
                $pdf = (new BeplyHtmlRenderService())->renderChatPresentation($config, $document['spec'], $branding);
                if ($pdf === '') {
                    throw new \RuntimeException('presentation-pdf-empty');
                }
                $this->send($pdf, 'application/pdf', DocumentService::filename($title, 'pdf'), 'attachment');
                return;
            }
            if ($format === 'csv') {
                $sheet = (int) $this->request->query('sheet', '0');
                $sheetName = (string) ($document['spec']['sheets'][$sheet]['name'] ?? '');
                $name = DocumentService::filename($title . ($sheet > 0 && $sheetName !== '' ? ' ' . $sheetName : ''), 'csv');
                $this->send(SpreadsheetCsvWriter::render($document['spec'], $sheet), SpreadsheetCsvWriter::MIME, $name, 'attachment');
                return;
            }
            $this->send(SpreadsheetXlsxWriter::render($document['spec'], $branding), SpreadsheetXlsxWriter::MIME, DocumentService::filename($title, 'xlsx'), 'attachment');
        } catch (Throwable $e) {
            Tools::log()->error('beplypdf-chat-office-export-failed: ' . get_class($e));
            $this->response->setHttpCode(500);
            $this->response->headers->set('Content-Type', 'text/plain; charset=utf-8');
            $this->response->setContent('No se pudo generar el archivo.');
        }
    }

    private function notFound(string $message): void
    {
        $this->response->setHttpCode(404);
        $this->response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $this->response->setContent($message);
    }

    /** URL relativa del mismo origen (el visor y las descargas nunca usan URLs del modelo). */
    public static function documentUrl(int $id, string $query = ''): string
    {
        return 'PdfStudioChatDocument?id=' . $id . ($query !== '' ? '&' . $query : '');
    }

    private function send(string $bytes, string $mime, string $name, string $disposition): void
    {
        $this->response->headers->set('Content-Type', $mime);
        $this->response->headers->set('Content-Disposition', $disposition . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        $this->response->headers->set('Content-Length', (string) strlen($bytes));
        $this->response->setContent($bytes);
    }
}
