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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Extension\Controller;

use Closure;

class EditController
{
    private const DOCUMENT_MODELS = [
        'PresupuestoCliente',
        'PedidoCliente',
        'AlbaranCliente',
        'FacturaCliente',
        'PresupuestoProveedor',
        'PedidoProveedor',
        'AlbaranProveedor',
        'FacturaProveedor',
    ];

    public function addFileAction(): Closure
    {
        $models = self::DOCUMENT_MODELS;

        return function ($fileRelation, $request) use ($models): void {
            if (!isset($fileRelation->model) || !in_array((string) $fileRelation->model, $models, true)) {
                return;
            }
            try {
                $file = method_exists($fileRelation, 'getFile') ? $fileRelation->getFile() : null;
                if (!is_object($file) || (!$file->isPdf() && !$file->isImage())) {
                    $fileRelation->beply_pdf_print = false;
                    return;
                }
            } catch (\Throwable $e) {
                $fileRelation->beply_pdf_print = false;
                return;
            }

            $value = null;
            if (is_object($request) && method_exists($request, 'input')) {
                $value = $request->input('beply_pdf_print', null);
            }
            if ($value === null && is_object($request) && isset($request->request) && method_exists($request->request, 'get')) {
                $value = $request->request->get('beply_pdf_print', null);
            }
            $fileRelation->beply_pdf_print = in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
        };
    }

    public function editFileAction(): Closure
    {
        $models = self::DOCUMENT_MODELS;

        return function ($fileRelation, $request) use ($models): void {
            if (!isset($fileRelation->model) || !in_array((string) $fileRelation->model, $models, true)) {
                return;
            }
            try {
                $file = method_exists($fileRelation, 'getFile') ? $fileRelation->getFile() : null;
                if (!is_object($file) || (!$file->isPdf() && !$file->isImage())) {
                    $fileRelation->beply_pdf_print = false;
                    return;
                }
            } catch (\Throwable $e) {
                $fileRelation->beply_pdf_print = false;
                return;
            }

            $value = null;
            if (is_object($request) && method_exists($request, 'input')) {
                $value = $request->input('beply_pdf_print', null);
            }
            if ($value === null && is_object($request) && isset($request->request) && method_exists($request->request, 'get')) {
                $value = $request->request->get('beply_pdf_print', null);
            }
            $fileRelation->beply_pdf_print = in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
        };
    }
}
