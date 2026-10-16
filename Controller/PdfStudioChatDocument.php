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
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use Throwable;

/**
 * Un documento del chat (modo Documentos o informe de auditoría), pintado con el
 * estilo de PDF Studio de su empresa (o el global):
 *
 * | Petición | Respuesta |
 * |---|---|
 * | `GET ?id=N` / `?id=N&embed=1` | El PDF en línea (vista previa del panel del chat, con sus páginas) |
 * | `GET ?id=N&format=pdf` | El PDF para descargar |
 * | `GET ?id=N&format=docx` | Word con los colores, la fuente y el logo del estilo |
 *
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
        $document = BeplyToolProvider::service()->find((string) $this->user->nick, $id);
        if ($document === null) {
            $this->response->setHttpCode(404);
            $this->response->headers->set('Content-Type', 'text/plain; charset=utf-8');
            $this->response->setContent('Este documento no existe o no es tuyo.');
            return;
        }

        $format = (string) $this->request->query('format', '');
        $idempresa = isset($document['idempresa']) ? (int) $document['idempresa'] : null;
        try {
            if ($format === 'docx') {
                $bytes = DocumentDocxWriter::render($document['spec'], DocumentBranding::resolve($idempresa));
                $this->send($bytes, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', DocumentService::filename((string) $document['title'], 'docx'), 'attachment');
                return;
            }
            $config = (new BeplyPdfRenderService())->resolveConfig(null, $idempresa) ?? new BeplyPdfConfig();
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
        $this->send($bytes, 'application/pdf', DocumentService::filename((string) $document['title'], 'pdf'), $disposition);
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
