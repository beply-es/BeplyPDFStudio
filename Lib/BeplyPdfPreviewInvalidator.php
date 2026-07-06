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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Dinamic\Model\BeplyPdfStyle;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

final class BeplyPdfPreviewInvalidator
{
    private const SUBDIR = 'beplypdf';

    public function invalidateAll(): int
    {
        $base = $this->baseDir();
        if ($base === '') {
            return 0;
        }

        $deleted = 0;
        foreach ([
            '/preview_design_*.webp',
            '/preview_[0-9]*_*.webp',
            '/preview_[0-9]*_*.pdf',
            '/preview_real_*.pdf',
            '/preview_real_*.pdf.hash',
            '/tmp_real_*.pdf',
            '/tmp_*.svg',
            '/tmp_*.png',
            '/tmp_pdf_*.svg',
        ] as $pattern) {
            foreach (glob($base . $pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Rebuilds the real gallery previews for the selected company context.
     *
     * External fiscal plugins can call this after their preview signature changes.
     */
    public function rebuildForCompany(?int $idempresa = null): array
    {
        $result = $this->rebuildForCompanies([$idempresa], true);
        $out = [
            'deleted' => $result['deleted'],
            'generated' => $result['generated'],
            'failed' => $result['failed'],
            'skipped' => $result['skipped'],
        ];
        if (isset($result['reason'])) {
            $out['reason'] = $result['reason'];
        }

        return $out;
    }

    /**
     * Rebuilds gallery previews for several company contexts, deleting stale files once.
     *
     * @param array<int|null> $idempresas
     */
    public function rebuildForCompanies(array $idempresas, bool $deleteFirst = true): array
    {
        if (false === $this->schemaReady()) {
            return [
                'deleted' => 0,
                'generated' => 0,
                'failed' => 0,
                'skipped' => 1,
                'reason' => 'schema-not-ready',
            ];
        }

        $deleted = $deleteFirst ? $this->invalidateAll() : 0;
        $preview = new BeplyPdfPreviewService();
        $generated = 0;
        $failed = 0;
        $seen = [];

        foreach ($idempresas === [] ? [null] : $idempresas as $idempresa) {
            $idempresa = $idempresa === null ? null : (int) $idempresa;
            $companyKey = $idempresa === null ? 'default' : (string) $idempresa;
            if (isset($seen[$companyKey])) {
                continue;
            }
            $seen[$companyKey] = true;

            foreach (array_keys(AbstractBeplyPdfLayout::registry()) as $key) {
                $url = $preview->urlForDesignKey($key, $idempresa);
                $url === '' ? $failed++ : $generated++;
            }

            foreach (BeplyPdfStyle::all([], ['id' => 'ASC'], 0, 0) as $style) {
                if (false === $style instanceof BeplyPdfStyle || false === $this->styleAppliesToCompany($style, $idempresa)) {
                    continue;
                }
                if (false === $preview->isCustomized($style)) {
                    continue;
                }

                $url = $preview->urlFor($style);
                $url === '' ? $failed++ : $generated++;
            }
        }

        return [
            'deleted' => $deleted,
            'generated' => $generated,
            'failed' => $failed,
            'skipped' => 0,
        ];
    }

    private function baseDir(): string
    {
        if (false === defined('FS_FOLDER')) {
            return '';
        }

        $base = FS_FOLDER . '/MyFiles/' . self::SUBDIR;
        if (false === is_dir($base)) {
            @mkdir($base, 0775, true);
        }

        return is_dir($base) ? $base : '';
    }

    private function schemaReady(): bool
    {
        $db = new DataBase();
        if (!$db->connect()) {
            return false;
        }

        return $db->tableExists('formatos_documentos')
            && $db->tableExists('beply_pdf_styles');
    }

    private function styleAppliesToCompany(BeplyPdfStyle $style, ?int $idempresa): bool
    {
        if ($idempresa === null || $idempresa < 1) {
            return true;
        }

        $styleCompany = $style->idempresa === null ? null : (int) $style->idempresa;
        return $styleCompany === null || $styleCompany === $idempresa;
    }
}
