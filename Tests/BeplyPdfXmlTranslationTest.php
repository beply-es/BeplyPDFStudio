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

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use PHPUnit\Framework\TestCase;

final class BeplyPdfXmlTranslationTest extends TestCase
{
    public function testSelectValuesUseTranslatableSlugs(): void
    {
        $translations = $this->translations();
        $selects = [
            'BpsLineas.xml' => ['fieldname', 'align', 'coltype'],
            'BpsLogo.xml' => ['logo_position'],
            'BpsTextos.xml' => ['footer_align', 'page_footer_align'],
            'BpsPagina.xml' => ['orientation'],
        ];

        foreach ($selects as $file => $fieldNames) {
            $xml = $this->loadXml($file);
            foreach ($fieldNames as $fieldName) {
                $widgets = $xml->xpath('//widget[@type="select"][@fieldname="' . $fieldName . '"]');
                $this->assertSame(1, count($widgets), "{$file}: select {$fieldName} debe existir una vez");

                $widget = $widgets[0];
                $this->assertSame('true', (string) $widget['translate'], "{$file}: {$fieldName} debe traducir sus values");

                foreach ($widget->values as $value) {
                    $slug = (string) $value['title'];
                    $this->assertTrue((bool) preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug), "{$file}: {$fieldName} usa slug inválido {$slug}");
                    $this->assertTrue(isset($translations['es'][$slug]), "{$file}: falta traducción ES para {$slug}");
                    $this->assertTrue(isset($translations['en'][$slug]), "{$file}: falta traducción EN para {$slug}");
                }
            }
        }
    }

    public function testLineEditorLabelsHaveTranslations(): void
    {
        $translations = $this->translations();
        foreach (['order', 'field', 'alignment', 'type', 'width'] as $slug) {
            $this->assertTrue(isset($translations['es'][$slug]), "falta traducción ES para {$slug}");
            $this->assertTrue(isset($translations['en'][$slug]), "falta traducción EN para {$slug}");
        }
    }

    private function loadXml(string $file): \SimpleXMLElement
    {
        $path = dirname(__DIR__) . '/XMLView/' . $file;
        $xml = simplexml_load_file($path);
        $this->assertTrue($xml instanceof \SimpleXMLElement, "{$file} debe ser XML válido");
        return $xml;
    }

    private function translations(): array
    {
        $base = dirname(__DIR__) . '/Translation/';
        return [
            'es' => json_decode(file_get_contents($base . 'es_ES.json'), true),
            'en' => json_decode(file_get_contents($base . 'en_EN.json'), true),
        ];
    }
}
