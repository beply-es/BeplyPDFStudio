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

/**
 * Table XML must produce DDL that both PostgreSQL and MySQL 8 accept.
 * MySQL rejects an AUTO_INCREMENT column that is not a key (ERROR 1075) and a
 * literal DEFAULT on TEXT/BLOB/JSON columns (ERROR 1101).
 */
final class TablePortableDdlContractTest extends TestCase
{
    public function testEverySerialColumnIsThePrimaryKey(): void
    {
        $offenders = [];
        foreach ($this->tableFiles() as $file) {
            $primary = $this->primaryKey($file);
            foreach (simplexml_load_file($file)->column as $column) {
                if (strtolower(trim((string) $column->type)) === 'serial' && !in_array((string) $column->name, $primary, true)) {
                    $offenders[] = basename($file) . ':' . $column->name;
                }
            }
        }

        $this->assertSame([], $offenders, 'MySQL ERROR 1075: an AUTO_INCREMENT column must be the key: ' . implode(', ', $offenders));
    }

    public function testColumnsTableFollowsTheStylesPrimaryKeyConvention(): void
    {
        $this->assertSame(['id'], $this->primaryKey($this->root() . '/Table/beply_pdf_styles.xml'));
        $this->assertSame(['id'], $this->primaryKey($this->root() . '/Table/beply_pdf_columns.xml'));

        $names = [];
        foreach (simplexml_load_file($this->root() . '/Table/beply_pdf_columns.xml')->constraint as $constraint) {
            $names[] = (string) $constraint->name;
        }
        $this->assertSame(['beply_pdf_columns_pkey', 'ca_beply_pdf_columns_style'], $names);
    }

    public function testNoLargeObjectColumnDeclaresALiteralDefault(): void
    {
        $offenders = [];
        foreach ($this->tableFiles() as $file) {
            foreach (simplexml_load_file($file)->column as $column) {
                $type = strtolower(trim((string) $column->type));
                if (preg_match('/^(tiny|medium|long)?(text|blob)$|^json$|^geometry$/', $type) === 1 && isset($column->default)) {
                    $offenders[] = basename($file) . ':' . $column->name;
                }
            }
        }

        $this->assertSame([], $offenders, 'MySQL ERROR 1101: TEXT/BLOB/JSON columns cannot have a literal default: ' . implode(', ', $offenders));
    }

    private function root(): string
    {
        return dirname(__DIR__);
    }

    /** @return string[] */
    private function tableFiles(): array
    {
        $files = glob($this->root() . '/Table/*.xml') ?: [];
        $this->assertTrue(count($files) > 0, 'no Table/*.xml found');
        sort($files, SORT_STRING);
        return $files;
    }

    /** @return string[] */
    private function primaryKey(string $file): array
    {
        foreach (simplexml_load_file($file)->constraint as $constraint) {
            if (preg_match('/^PRIMARY KEY\s*\(([^)]*)\)/i', trim((string) $constraint->type), $match) === 1) {
                return array_map('trim', explode(',', $match[1]));
            }
        }
        return [];
    }
}
