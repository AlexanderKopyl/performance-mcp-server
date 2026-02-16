<?php

declare(strict_types=1);

namespace App\Application\Collector\Service;

use RuntimeException;

final class HttpTimingProbe
{
    /**
     * @param list<string> $urlPaths
     * @param array<string, string> $headers
     * @param array{strip_query_params:bool, redact_url_userinfo:bool} $redactionRules
     * @return array{method:string,samples:list<array<string,mixed>>}
     */
    public function probe(
        string $baseUrl,
        array $urlPaths,
        int $sampleCount,
        int $concurrency,
        int $timeoutMs,
        int $warmupCount,
        array $headers,
        array $redactionRules,
    ): array {
        if (!function_exists('curl_multi_init')) {
            throw new RuntimeException('curl extension is required for collect.run timing probe.');
        }

        $jobs = [];
        foreach ($urlPaths as $path) {
            $fullUrl = $this->buildUrl($baseUrl, $path);

            for ($warmupIndex = 0; $warmupIndex < $warmupCount; ++$warmupIndex) {
                $jobs[] = [
                    'path' => $path,
                    'url' => $fullUrl,
                    'is_warmup' => true,
                ];
            }

            for ($sampleIndex = 0; $sampleIndex < $sampleCount; ++$sampleIndex) {
                $jobs[] = [
                    'path' => $path,
                    'url' => $fullUrl,
                    'is_warmup' => false,
                ];
            }
        }

        $multi = curl_multi_init();
        $pending = $jobs;
        $active = [];
        $completedSamples = [];
        $sequence = 0;
        $sampleOrdinal = 0;

        try {
            do {
                while (count($active) < $concurrency && $pending !== []) {
                    $job = array_shift($pending);
                    if (!is_array($job)) {
                        continue;
                    }

                    $ch = curl_init();
                    if ($ch === false) {
                        throw new RuntimeException('Failed to initialize curl handle.');
                    }

                    $headerLines = [];
                    foreach ($headers as $name => $value) {
                        $headerLines[] = sprintf('%s: %s', $name, $value);
                    }

                    $startedAt = microtime(true);
                    curl_setopt_array($ch, [
                        CURLOPT_URL => (string) $job['url'],
                        CURLOPT_CUSTOMREQUEST => 'GET',
                        CURLOPT_RETURNTRANSFER => false,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
                        CURLOPT_TIMEOUT_MS => $timeoutMs,
                        CURLOPT_HTTPHEADER => $headerLines,
                        CURLOPT_HEADER => false,
                        CURLOPT_WRITEFUNCTION => static fn ($curl, string $chunk): int => strlen($chunk),
                        CURLOPT_USERAGENT => 'mcp-perf-server/collect-run',
                    ]);

                    curl_multi_add_handle($multi, $ch);

                    $active[(int) $ch] = [
                        'handle' => $ch,
                        'path' => (string) $job['path'],
                        'url' => (string) $job['url'],
                        'is_warmup' => (bool) $job['is_warmup'],
                        'started_at_unix' => $startedAt,
                        'started_at_iso' => $this->iso8601FromFloat($startedAt),
                        'sequence' => $sequence,
                    ];

                    ++$sequence;
                }

                do {
                    $execStatus = curl_multi_exec($multi, $running);
                } while ($execStatus === CURLM_CALL_MULTI_PERFORM);

                while (($info = curl_multi_info_read($multi)) !== false) {
                    $ch = $info['handle'] ?? null;
                    if (!is_object($ch)) {
                        continue;
                    }

                    $meta = $active[(int) $ch] ?? null;
                    if (!is_array($meta)) {
                        curl_multi_remove_handle($multi, $ch);
                        curl_close($ch);
                        continue;
                    }

                    unset($active[(int) $ch]);

                    $endedAt = microtime(true);
                    $curlInfo = curl_getinfo($ch);
                    $errno = curl_errno($ch);
                    $errorMessage = $errno !== 0 ? curl_error($ch) : null;

                    if (!$meta['is_warmup']) {
                        ++$sampleOrdinal;
                        $completedSamples[] = $this->buildSampleRecord(
                            path: (string) $meta['path'],
                            url: (string) $meta['url'],
                            statusCode: is_array($curlInfo) ? (int) ($curlInfo['http_code'] ?? 0) : 0,
                            startedAtIso: (string) $meta['started_at_iso'],
                            endedAtIso: $this->iso8601FromFloat($endedAt),
                            timings: is_array($curlInfo) ? $curlInfo : [],
                            redactionRules: $redactionRules,
                            sequence: (int) $meta['sequence'],
                            sampleOrdinal: $sampleOrdinal,
                            errno: $errno,
                            errorMessage: $errorMessage,
                        );
                    }

                    curl_multi_remove_handle($multi, $ch);
                    curl_close($ch);
                }

                if ($running > 0) {
                    curl_multi_select($multi, 0.2);
                }
            } while ($pending !== [] || $active !== [] || $running > 0);
        } finally {
            foreach ($active as $meta) {
                if (!is_array($meta) || !isset($meta['handle']) || !is_object($meta['handle'])) {
                    continue;
                }

                curl_multi_remove_handle($multi, $meta['handle']);
                curl_close($meta['handle']);
            }
            curl_multi_close($multi);
        }

        usort(
            $completedSamples,
            static fn (array $a, array $b): int => (($a['_sequence'] ?? 0) <=> ($b['_sequence'] ?? 0)),
        );

        foreach ($completedSamples as &$sample) {
            unset($sample['_sequence']);
        }
        unset($sample);

        return [
            'method' => 'curl_getinfo.starttransfer_time + total_time',
            'samples' => $completedSamples,
        ];
    }

    private function buildUrl(string $baseUrl, string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    /**
     * @param array<string, mixed> $timings
     * @param array{strip_query_params:bool, redact_url_userinfo:bool} $redactionRules
     * @return array<string, mixed>
     */
    private function buildSampleRecord(
        string $path,
        string $url,
        int $statusCode,
        string $startedAtIso,
        string $endedAtIso,
        array $timings,
        array $redactionRules,
        int $sequence,
        int $sampleOrdinal,
        int $errno,
        ?string $errorMessage,
    ): array {
        $namelookupMs = $this->secToMs($timings['namelookup_time'] ?? null);
        $connectMs = $this->secToMs($timings['connect_time'] ?? null);
        $appConnectMs = $this->secToMs($timings['appconnect_time'] ?? null);
        $preTransferMs = $this->secToMs($timings['pretransfer_time'] ?? null);
        $startTransferMs = $this->secToMs($timings['starttransfer_time'] ?? null);
        $redirectMs = $this->secToMs($timings['redirect_time'] ?? null);
        $totalMs = $this->secToMs($timings['total_time'] ?? null);

        if ($errno !== 0) {
            $startTransferMs = null;
        }

        return [
            'sample_id' => sprintf('timing-%06d', $sampleOrdinal),
            'path' => $this->redactPath($path, $redactionRules),
            'url' => $this->redactUrl($url, $redactionRules),
            'status' => $statusCode > 0 ? $statusCode : null,
            'started_at' => $startedAtIso,
            'ended_at' => $endedAtIso,
            'ttfb_ms' => $startTransferMs,
            'total_ms' => $totalMs,
            'raw_timings' => [
                'namelookup_ms' => $namelookupMs,
                'connect_ms' => $connectMs,
                'appconnect_ms' => $appConnectMs,
                'pretransfer_ms' => $preTransferMs,
                'starttransfer_ms' => $startTransferMs,
                'redirect_ms' => $redirectMs,
                'total_ms' => $totalMs,
            ],
            'error' => $errno === 0 ? null : [
                'code' => $errno,
                'message' => $errorMessage ?? 'unknown curl error',
            ],
            '_sequence' => $sequence,
        ];
    }

    /**
     * @param array{strip_query_params:bool, redact_url_userinfo:bool} $redactionRules
     */
    private function redactUrl(string $url, array $redactionRules): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']).'://' : '';
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');

        $query = '';
        if (!$redactionRules['strip_query_params'] && isset($parts['query'])) {
            $query = '?'.$parts['query'];
        }

        $auth = '';
        if (!$redactionRules['redact_url_userinfo'] && isset($parts['user'])) {
            $auth = (string) $parts['user'];
            if (isset($parts['pass'])) {
                $auth .= ':'.(string) $parts['pass'];
            }
            $auth .= '@';
        }

        return $scheme.$auth.$host.$port.$path.$query;
    }

    /**
     * @param array{strip_query_params:bool, redact_url_userinfo:bool} $redactionRules
     */
    private function redactPath(string $path, array $redactionRules): string
    {
        if (!$redactionRules['strip_query_params']) {
            return $path;
        }

        $queryPos = strpos($path, '?');
        if ($queryPos === false) {
            return $path;
        }

        return substr($path, 0, $queryPos);
    }

    private function secToMs(mixed $value): ?float
    {
        if (!is_float($value) && !is_int($value) && !is_numeric($value)) {
            return null;
        }

        return round(((float) $value) * 1000, 3);
    }

    private function iso8601FromFloat(float $unixTs): string
    {
        $seconds = (int) floor($unixTs);
        $micro = (int) round(($unixTs - $seconds) * 1_000_000);

        return sprintf('%s.%06dZ', gmdate('Y-m-d\\TH:i:s', $seconds), $micro);
    }
}
