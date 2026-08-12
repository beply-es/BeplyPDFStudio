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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Tests\Lib;

/**
 * Modelo medible de un PDF ya renderizado.
 *
 * El testing anterior solo sabia decir "el render cambia" o "esta cadena aparece en el HTML".
 * Eso deja pasar fallos reales: un logo que se queda a la derecha, unos totales que se salen
 * del papel, una lista markdown que se imprime como asteriscos. Esta clase extrae la GEOMETRIA
 * real del PDF (cajas de palabras, cajas de imagenes, fuentes embebidas) para poder afirmar
 * donde esta cada cosa y si se sale del area imprimible.
 *
 * Se apoya en poppler (pdftotext -bbox-layout, pdftohtml -xml, pdffonts), disponible en la
 * imagen de runtime. Todas las coordenadas se normalizan a PUNTOS PDF con origen arriba-izquierda.
 */
final class BeplyPdfProbe
{
    /** @var array<int, array{width: float, height: float, words: array<int, array>, images: array<int, array>}> */
    private array $pages = [];

    /** @var array<int, string> */
    private array $fonts = [];

    private string $text = '';

    private function __construct()
    {
    }

    public static function fromBytes(string $pdf): self
    {
        $probe = new self();
        if ($pdf === '') {
            return $probe;
        }

        $dir = sys_get_temp_dir() . '/bpdfprobe_' . bin2hex(random_bytes(6));
        @mkdir($dir, 0o777, true);
        $file = $dir . '/doc.pdf';
        file_put_contents($file, $pdf);

        $probe->loadWords($file);
        $probe->loadImages($file);
        $probe->loadFonts($file);
        $probe->loadText($file);

        self::rmdir($dir);
        return $probe;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function pageWidth(int $page = 1): float
    {
        return (float) ($this->pages[$page]['width'] ?? 0.0);
    }

    public function pageHeight(int $page = 1): float
    {
        return (float) ($this->pages[$page]['height'] ?? 0.0);
    }

    /** Texto plano completo del PDF. */
    public function text(): string
    {
        return $this->text;
    }

    /** Texto normalizado a una sola linea, util para buscar frases sin depender del salto. */
    public function flatText(): string
    {
        return trim(preg_replace('/\s+/u', ' ', $this->text) ?? $this->text);
    }

    /** @return array<int, string> */
    public function fonts(): array
    {
        return $this->fonts;
    }

    public function hasBoldFont(): bool
    {
        return $this->matchesFont('/bold|black|heavy|semibold|[-,]bd\b/i');
    }

    public function hasItalicFont(): bool
    {
        return $this->matchesFont('/italic|oblique/i');
    }

    /**
     * Palabras de una pagina (o de todas si $page es null).
     * Cada palabra: ['page','text','x0','y0','x1','y1'].
     * @return array<int, array>
     */
    public function words(?int $page = null): array
    {
        if ($page !== null) {
            return $this->pages[$page]['words'] ?? [];
        }

        $out = [];
        foreach ($this->pages as $data) {
            foreach ($data['words'] as $word) {
                $out[] = $word;
            }
        }
        return $out;
    }

    /**
     * Imagenes de una pagina (o de todas). Cada imagen: ['page','x0','y0','x1','y1'].
     * @return array<int, array>
     */
    public function images(?int $page = null): array
    {
        if ($page !== null) {
            return $this->pages[$page]['images'] ?? [];
        }

        $out = [];
        foreach ($this->pages as $data) {
            foreach ($data['images'] as $image) {
                $out[] = $image;
            }
        }
        return $out;
    }

    /** Imagen mas grande de la pagina indicada: en nuestras plantillas es el logo. */
    public function largestImage(int $page = 1): ?array
    {
        $best = null;
        $bestArea = 0.0;
        foreach ($this->images($page) as $image) {
            $area = ($image['x1'] - $image['x0']) * ($image['y1'] - $image['y0']);
            if ($area > $bestArea) {
                $bestArea = $area;
                $best = $image;
            }
        }
        return $best;
    }

    /**
     * Busca la primera palabra cuyo texto contenga $needle (sin distinguir mayusculas).
     * @return array|null
     */
    public function findWord(string $needle, ?int $page = null): ?array
    {
        foreach ($this->words($page) as $word) {
            if (mb_stripos($word['text'], $needle) !== false) {
                return $word;
            }
        }
        return null;
    }

    /** @return array<int, array> */
    public function findWords(string $needle, ?int $page = null): array
    {
        $out = [];
        foreach ($this->words($page) as $word) {
            if (mb_stripos($word['text'], $needle) !== false) {
                $out[] = $word;
            }
        }
        return $out;
    }

    /**
     * Localiza una frase completa (varias palabras consecutivas en la misma linea).
     * Devuelve la caja envolvente o null.
     */
    public function findPhrase(string $phrase, ?int $page = null): ?array
    {
        $needles = preg_split('/\s+/u', trim($phrase)) ?: [];
        if (empty($needles)) {
            return null;
        }

        $words = $this->words($page);
        $total = count($words);
        for ($i = 0; $i < $total; $i++) {
            $box = null;
            $ok = true;
            foreach ($needles as $offset => $needle) {
                $word = $words[$i + $offset] ?? null;
                if ($word === null || mb_stripos($word['text'], $needle) === false) {
                    $ok = false;
                    break;
                }
                $box = $box === null ? $word : self::merge($box, $word);
            }
            if ($ok && $box !== null) {
                return $box;
            }
        }
        return null;
    }

    /**
     * Caja envolvente de todo el contenido (texto + imagenes) de una pagina.
     * @return array{x0: float, y0: float, x1: float, y1: float}|null
     */
    public function contentBounds(int $page = 1): ?array
    {
        $box = null;
        foreach (array_merge($this->words($page), $this->images($page)) as $item) {
            $box = $box === null ? $item : self::merge($box, $item);
        }
        if ($box === null) {
            return null;
        }
        return ['x0' => $box['x0'], 'y0' => $box['y0'], 'x1' => $box['x1'], 'y1' => $box['y1']];
    }

    /**
     * Elementos que se salen del area imprimible por cualquier lado.
     * $tolerance absorbe el antialias/redondeo de poppler.
     * @return array<int, array>
     */
    public function overflowing(float $marginPt, float $tolerance = 1.5): array
    {
        $out = [];
        foreach ($this->pages as $number => $data) {
            $limitRight = $data['width'] - $marginPt + $tolerance;
            $limitBottom = $data['height'] - $marginPt + $tolerance;
            $limitLeft = $marginPt - $tolerance;
            $limitTop = $marginPt - $tolerance;
            foreach (array_merge($data['words'], $data['images']) as $item) {
                if ($item['x1'] > $limitRight || $item['x0'] < $limitLeft
                    || $item['y1'] > $limitBottom || $item['y0'] < $limitTop) {
                    $item['page'] = $number;
                    $out[] = $item;
                }
            }
        }
        return $out;
    }

    /** Paginas sin una sola palabra ni imagen: sintoma clasico de paginacion rota. */
    public function blankPages(): array
    {
        $out = [];
        foreach ($this->pages as $number => $data) {
            if (empty($data['words']) && empty($data['images'])) {
                $out[] = $number;
            }
        }
        return $out;
    }

    private function matchesFont(string $regex): bool
    {
        foreach ($this->fonts as $font) {
            if (1 === preg_match($regex, $font)) {
                return true;
            }
        }
        return false;
    }

    private function loadWords(string $file): void
    {
        $xml = self::run(['pdftotext', '-bbox-layout', $file, '-']);
        if ($xml === '') {
            return;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            return;
        }

        // pdftotext -bbox-layout emite XHTML con namespace por defecto: sin registrarlo,
        // cualquier xpath devuelve vacio y el PDF pareceria una pagina en blanco.
        $namespaces = $doc->getDocNamespaces(true);
        $prefix = '';
        if (!empty($namespaces[''])) {
            $doc->registerXPathNamespace('x', $namespaces['']);
            $prefix = 'x:';
        }

        $number = 0;
        foreach ($doc->body->doc->page as $page) {
            $number++;
            $this->pages[$number] = [
                'width' => (float) $page['width'],
                'height' => (float) $page['height'],
                'words' => [],
                'images' => [],
            ];
            if ($prefix !== '') {
                $page->registerXPathNamespace('x', $namespaces['']);
            }
            foreach ($page->xpath('.//' . $prefix . 'word') ?: [] as $word) {
                $text = (string) $word;
                if (trim($text) === '') {
                    continue;
                }
                $this->pages[$number]['words'][] = [
                    'page' => $number,
                    'text' => $text,
                    'x0' => (float) $word['xMin'],
                    'y0' => (float) $word['yMin'],
                    'x1' => (float) $word['xMax'],
                    'y1' => (float) $word['yMax'],
                ];
            }
        }
    }

    /**
     * pdftohtml -xml da las cajas de imagen, pero en un espacio escalado propio.
     * Se reescala a puntos PDF usando la altura/anchura de pagina ya conocida.
     */
    private function loadImages(string $file): void
    {
        // OJO: pdftohtml -i IGNORA las imagenes. Hay que llamarlo sin -i y desde el
        // directorio temporal, porque vuelca los PNG extraidos junto al ejecutable.
        $xml = self::run(['pdftohtml', '-xml', '-stdout', $file], dirname($file));
        if ($xml === '') {
            return;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            return;
        }

        foreach ($doc->page as $page) {
            $number = (int) $page['number'];
            if (!isset($this->pages[$number])) {
                continue;
            }
            $rawWidth = (float) $page['width'];
            $rawHeight = (float) $page['height'];
            if ($rawWidth <= 0.0 || $rawHeight <= 0.0) {
                continue;
            }
            $scaleX = $this->pages[$number]['width'] / $rawWidth;
            $scaleY = $this->pages[$number]['height'] / $rawHeight;

            foreach ($page->image as $image) {
                $x0 = ((float) $image['left']) * $scaleX;
                $y0 = ((float) $image['top']) * $scaleY;
                $this->pages[$number]['images'][] = [
                    'page' => $number,
                    'text' => '[image]',
                    'x0' => $x0,
                    'y0' => $y0,
                    'x1' => $x0 + ((float) $image['width']) * $scaleX,
                    'y1' => $y0 + ((float) $image['height']) * $scaleY,
                ];
            }
        }
    }

    private function loadFonts(string $file): void
    {
        $raw = self::run(['pdffonts', $file]);
        foreach (preg_split('/\R/u', $raw) ?: [] as $index => $line) {
            if ($index < 2 || trim($line) === '') {
                continue;
            }
            $name = trim(substr($line, 0, 37));
            if ($name !== '') {
                $this->fonts[] = $name;
            }
        }
    }

    private function loadText(string $file): void
    {
        $this->text = self::run(['pdftotext', '-layout', '-enc', 'UTF-8', $file, '-']);
    }

    private static function merge(array $a, array $b): array
    {
        return [
            'page' => $a['page'] ?? ($b['page'] ?? 1),
            'text' => trim(($a['text'] ?? '') . ' ' . ($b['text'] ?? '')),
            'x0' => min($a['x0'], $b['x0']),
            'y0' => min($a['y0'], $b['y0']),
            'x1' => max($a['x1'], $b['x1']),
            'y1' => max($a['y1'], $b['y1']),
        ];
    }

    private static function run(array $command, ?string $cwd = null): string
    {
        $cmd = implode(' ', array_map('escapeshellarg', $command)) . ' 2>/dev/null';
        if ($cwd !== null) {
            $cmd = 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd;
        }
        $out = @shell_exec($cmd);
        return is_string($out) ? $out : '';
    }

    private static function rmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $path) {
            is_dir($path) ? self::rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
