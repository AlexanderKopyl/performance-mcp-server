<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

final class LogRedactor
{
    private const SENSITIVE_KEYS = [
        'authorization',
        'cookie',
        'password',
        'secret',
        'token',
        'api_key',
        'set-cookie',
    ];

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function redact(array $context): array
    {
        $redacted = [];

        foreach ($context as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, self::SENSITIVE_KEYS, true)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }

            if (is_string($value) && $this->shouldRedactSql($normalizedKey, $value)) {
                $redacted[$key] = $this->redactSqlLiterals($value);
                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $redacted[$key] = $this->redact($value);
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    private function shouldRedactSql(string $key, string $value): bool
    {
        if (str_contains($key, 'sql') || str_contains($key, 'query')) {
            return true;
        }

        return (bool) preg_match('/\b(select|insert|update|delete)\b/i', $value);
    }

    private function redactSqlLiterals(string $sql): string
    {
        $sql = preg_replace("/'(?:''|[^'])*'/", "'?'", $sql) ?? $sql;
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;

        return $sql;
    }
}
