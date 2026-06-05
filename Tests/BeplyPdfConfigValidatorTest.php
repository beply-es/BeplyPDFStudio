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

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfigValidator;
use PHPUnit\Framework\TestCase;

final class BeplyPdfConfigValidatorTest extends TestCase
{
    private function v(): BeplyPdfConfigValidator
    {
        return new BeplyPdfConfigValidator();
    }

    public function testDefaultIsValid(): void
    {
        $this->assertTrue($this->v()->isValid(new BeplyPdfConfig()));
    }

    public function testRejectsInvalidMargin(): void
    {
        $c = new BeplyPdfConfig();
        $c->marginTop = 999;
        $this->assertContains('margen-invalido:marginTop', $this->v()->validate($c));
    }

    public function testRejectsInvalidColor(): void
    {
        $c = new BeplyPdfConfig();
        $c->colorPrimary = 'azul';
        $this->assertContains('color-invalido:colorPrimary', $this->v()->validate($c));
    }

    public function testHexHelper(): void
    {
        $v = $this->v();
        $this->assertTrue($v->isHex('#FFF'));
        $this->assertTrue($v->isHex('#1A1A2E'));
        $this->assertFalse($v->isHex('1A1A2E'));
    }

    public function testRejectsUnsupportedFont(): void
    {
        $c = new BeplyPdfConfig();
        $c->fontFamily = 'comic';
        $this->assertContains('fuente-no-disponible', $this->v()->validate($c));
    }

    public function testRejectsUnknownPaper(): void
    {
        $c = new BeplyPdfConfig();
        $c->paperSize = 'A0';
        $this->assertContains('papel-no-soportado', $this->v()->validate($c));
    }

    public function testRejectsMissingMinimumColumn(): void
    {
        $c = new BeplyPdfConfig();
        $c->lineColumns = ['referencia', 'pvptotal'];
        $c->lineColumnsAlign = ['left', 'right'];
        $c->lineColumnsType = ['text', 'money'];
        $this->assertContains('columna-minima-ausente:descripcion', $this->v()->validateColumns($c));
    }

    public function testRejectsUnknownColumn(): void
    {
        $c = new BeplyPdfConfig();
        $c->lineColumns = ['descripcion', 'inventada'];
        $c->lineColumnsAlign = ['left', 'left'];
        $c->lineColumnsType = ['text', 'text'];
        $this->assertContains('columna-desconocida:inventada', $this->v()->validateColumns($c));
    }

    public function testRejectsMisalignedColumnsMeta(): void
    {
        $c = new BeplyPdfConfig();
        $c->lineColumns = ['descripcion', 'pvptotal'];
        $c->lineColumnsAlign = ['left'];
        $c->lineColumnsType = ['text', 'money'];
        $errors = $this->v()->validateColumns($c);
        $this->assertContains('alineaciones-descuadradas', $errors);
    }

    public function testRejectsTooLongFooter(): void
    {
        $c = new BeplyPdfConfig();
        $c->footerText = str_repeat('a', BeplyPdfConfigValidator::MAX_FOOTER_LEN + 1);
        $this->assertContains('texto-final-demasiado-largo', $this->v()->validate($c));
    }
}
