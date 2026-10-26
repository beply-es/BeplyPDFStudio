<?php

declare(strict_types=1);

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentStore;

/** DocumentStore en memoria para los tests (sin FacturaScripts ni base de datos). */
final class InMemoryDocumentStore implements DocumentStore
{
    /** @var array<int, array> */
    public array $rows = [];
    private int $next = 1;
    private int $clock = 0;

    public function find(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function listByNick(string $nick, ?string $kind = null, int $limit = 50): array
    {
        $rows = array_values(array_filter($this->rows, static fn(array $row): bool => $row['nick'] === $nick && ($kind === null || $row['kind'] === $kind)));
        usort($rows, static fn(array $a, array $b): int => strcmp($b['last_update'], $a['last_update']) ?: $b['id'] <=> $a['id']);

        return array_slice($rows, 0, $limit);
    }

    public function insert(array $document): ?int
    {
        $id = $this->next++;
        $now = $this->tick();
        $this->rows[$id] = $document + ['id' => $id, 'creation_date' => $now, 'last_update' => $now];
        $this->rows[$id]['id'] = $id;

        return $id;
    }

    public function update(array $document): bool
    {
        $id = (int) $document['id'];
        if (false === isset($this->rows[$id])) {
            return false;
        }
        $document['last_update'] = $this->tick();
        $this->rows[$id] = $document;

        return true;
    }

    public function delete(int $id): bool
    {
        if (false === isset($this->rows[$id])) {
            return false;
        }
        unset($this->rows[$id]);

        return true;
    }

    /** @var array<string, array> */
    public array $versions = [];

    public function insertVersion(int $documentId, int $version, string $title, array $spec): bool
    {
        $key = $documentId . ':' . $version;
        if (isset($this->versions[$key])) {
            return false;
        }
        $this->versions[$key] = ['version' => $version, 'title' => $title, 'spec' => $spec, 'creation_date' => $this->tick()];

        return true;
    }

    public function findVersion(int $documentId, int $version): ?array
    {
        return $this->versions[$documentId . ':' . $version] ?? null;
    }

    /** @var array<int, array> */
    public array $styles = [];

    public function findStyle(?string $nick, ?int $idempresa, string $docType): ?array
    {
        $idempresa = $nick !== null ? null : $idempresa;
        foreach ($this->styles as $style) {
            if ($style['doc_type'] === $docType && $style['nick'] === $nick && $style['idempresa'] === $idempresa) {
                return $style;
            }
        }

        return null;
    }

    public function saveStyle(array $style): ?int
    {
        $nick = $style['nick'] ?? null;
        $idempresa = $nick === null ? ($style['idempresa'] ?? null) : null;
        $existing = $this->findStyle($nick, $idempresa, $style['doc_type']);
        $id = $existing['id'] ?? (count($this->styles) + 1);
        $this->styles[$id] = ['id' => $id, 'nick' => $nick, 'idempresa' => $idempresa, 'doc_type' => $style['doc_type'], 'label' => $style['label'], 'overrides' => $style['overrides'], 'last_update' => $this->tick()];

        return $id;
    }

    public function listStyles(string $nick, ?int $idempresa): array
    {
        return array_values(array_filter($this->styles, static fn(array $s): bool => $s['nick'] === $nick || ($s['nick'] === null && ($s['idempresa'] === null || $s['idempresa'] === $idempresa))));
    }

    private function tick(): string
    {
        $this->clock++;

        return sprintf('2026-10-16 10:%02d:%02d', intdiv($this->clock, 60), $this->clock % 60);
    }
}
