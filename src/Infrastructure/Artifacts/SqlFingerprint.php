<?php

declare(strict_types=1);

namespace App\Infrastructure\Artifacts;

final class SqlFingerprint
{
    public function fingerprint(string $sql): string
    {
        $normalized = $this->normalizeSql($sql);

        return hash('sha256', $normalized);
    }

    public function redactSql(string $sql): string
    {
        $sql = preg_replace("/'(?:''|[^'])*'/", "'?'", $sql) ?? $sql;
        $sql = preg_replace('/"(?:""|[^"])*"/', '"?"', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;

        return preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
    }

    private function normalizeSql(string $sql): string
    {
        $sql = mb_strtolower($this->redactSql($sql));

        return preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
    }
}
