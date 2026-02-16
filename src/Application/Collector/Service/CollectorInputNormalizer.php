<?php

declare(strict_types=1);

namespace App\Application\Collector\Service;

use InvalidArgumentException;

final readonly class CollectorInputNormalizer
{
    public function __construct(
        private int $defaultSampleCount,
        private int $defaultConcurrency,
        private int $defaultTimeoutMs,
        private int $defaultWarmupCount,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{
     *   spx_dirs:list<string>,
     *   slow_log_path:string,
     *   base_url:string,
     *   url_paths:list<string>,
     *   headers:array<string, string>,
     *   headers_allowlist:list<string>,
     *   sample_count:int,
     *   concurrency:int,
     *   timeout_ms:int,
     *   warmup_count:int,
     *   output_dir:?string,
     *   redaction_rules:array{strip_query_params:bool, redact_url_userinfo:bool},
     *   retention:array{keep_last_n:?int, ttl_days:?int}
     * }
     */
    public function normalize(array $params): array
    {
        $spxDirs = $this->readPathList($params, 'spx_dirs');
        foreach ($spxDirs as $dir) {
            if (!is_dir($dir)) {
                throw new InvalidArgumentException(sprintf('SPX directory not found: %s', $dir));
            }
        }

        $slowLogPath = $this->readString($params, 'slow_log_path');
        if (!is_file($slowLogPath)) {
            throw new InvalidArgumentException(sprintf('slow_log_path must point to a readable file: %s', $slowLogPath));
        }

        $baseUrl = $this->readString($params, 'base_url');
        $baseUrl = rtrim($baseUrl, '/');

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new InvalidArgumentException('base_url must be an absolute http(s) URL.');
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new InvalidArgumentException('base_url must contain a host.');
        }

        $urlPaths = $this->readPathList($params, 'url_paths');

        $allowlist = $this->readOptionalStringList($params, 'headers_allowlist');
        $allowlist = array_values(array_unique(array_map(static fn (string $header): string => strtolower($header), $allowlist)));

        $headers = $this->readHeaders($params['headers'] ?? null, $allowlist);

        $sampleCount = $this->readInt($params, 'sample_count', 1, 20, $this->defaultSampleCount);
        $concurrency = $this->readInt($params, 'concurrency', 1, 4, $this->defaultConcurrency);
        $timeoutMs = $this->readInt($params, 'timeout_ms', 200, 15_000, $this->defaultTimeoutMs);
        $warmupCount = $this->readInt($params, 'warmup_count', 0, 5, $this->defaultWarmupCount);

        $redactionRaw = $params['redaction_rules'] ?? [];
        if (!is_array($redactionRaw)) {
            throw new InvalidArgumentException('redaction_rules must be an object when provided.');
        }

        $redactionRules = [
            'strip_query_params' => (bool) ($redactionRaw['strip_query_params'] ?? true),
            'redact_url_userinfo' => (bool) ($redactionRaw['redact_url_userinfo'] ?? true),
        ];

        $outputDir = $params['output_dir'] ?? null;
        if ($outputDir !== null && (!is_string($outputDir) || trim($outputDir) === '')) {
            throw new InvalidArgumentException('output_dir must be a non-empty string when provided.');
        }

        $retentionRaw = $params['retention'] ?? [];
        if (!is_array($retentionRaw)) {
            throw new InvalidArgumentException('retention must be an object when provided.');
        }

        $keepLastN = $this->readNullableInt($retentionRaw, 'keep_last_n', 0, 10_000);
        $ttlDays = $this->readNullableInt($retentionRaw, 'ttl_days', 1, 3650);

        return [
            'spx_dirs' => $spxDirs,
            'slow_log_path' => $slowLogPath,
            'base_url' => $baseUrl,
            'url_paths' => $urlPaths,
            'headers' => $headers,
            'headers_allowlist' => $allowlist,
            'sample_count' => $sampleCount,
            'concurrency' => $concurrency,
            'timeout_ms' => $timeoutMs,
            'warmup_count' => $warmupCount,
            'output_dir' => $outputDir !== null ? trim($outputDir) : null,
            'redaction_rules' => $redactionRules,
            'retention' => [
                'keep_last_n' => $keepLastN,
                'ttl_days' => $ttlDays,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function readPathList(array $params, string $key): array
    {
        $raw = $params[$key] ?? null;
        if (!is_array($raw) || $raw === []) {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty list of strings.', $key));
        }

        $values = [];
        foreach ($raw as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('%s must be a non-empty list of strings.', $key));
            }

            $values[] = trim($item);
        }

        return array_values(array_unique($values));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function readString(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty string.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function readOptionalStringList(array $params, string $key): array
    {
        $raw = $params[$key] ?? null;
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            throw new InvalidArgumentException(sprintf('%s must be a list of strings.', $key));
        }

        $result = [];
        foreach ($raw as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('%s must be a list of strings.', $key));
            }

            $result[] = trim($item);
        }

        return $result;
    }

    /**
     * @param mixed $raw
     * @param list<string> $allowlist
     * @return array<string, string>
     */
    private function readHeaders(mixed $raw, array $allowlist): array
    {
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            throw new InvalidArgumentException('headers must be an object when provided.');
        }

        $result = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }

            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            $name = trim($key);
            if ($allowlist !== [] && !in_array(strtolower($name), $allowlist, true)) {
                continue;
            }

            $result[$name] = (string) $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function readInt(array $params, string $key, int $min, int $max, int $default): int
    {
        $raw = $params[$key] ?? null;
        if ($raw === null) {
            return $default;
        }

        if (!is_int($raw) && !(is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1)) {
            throw new InvalidArgumentException(sprintf('%s must be an integer.', $key));
        }

        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(sprintf('%s must be between %d and %d.', $key, $min, $max));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function readNullableInt(array $params, string $key, int $min, int $max): ?int
    {
        $raw = $params[$key] ?? null;
        if ($raw === null) {
            return null;
        }

        if (!is_int($raw) && !(is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1)) {
            throw new InvalidArgumentException(sprintf('%s must be an integer when provided.', $key));
        }

        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(sprintf('%s must be between %d and %d.', $key, $min, $max));
        }

        return $value;
    }
}
