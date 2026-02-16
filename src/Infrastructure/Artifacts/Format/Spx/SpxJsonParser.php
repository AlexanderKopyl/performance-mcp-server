<?php

declare(strict_types=1);

namespace App\Infrastructure\Artifacts\Format\Spx;

use App\Domain\Model\EvidenceRef;
use App\Domain\Model\RequestProfile;
use App\Domain\Model\Span;
use JsonException;

final class SpxJsonParser implements SpxParserInterface
{
    private const ENDPOINT_KEYS = ['route', 'url', 'endpoint', 'request_uri', 'uri', 'path'];
    private const SPAN_LABEL_KEYS = ['function', 'func', 'name', 'symbol', 'label'];
    private const SPAN_SELF_KEYS = ['self_ms', 'self_time_ms', 'selfTimeMs'];
    private const SPAN_TOTAL_KEYS = ['total_ms', 'total_time_ms', 'totalTimeMs', 'duration_ms'];

    public function parse(string $path, array $validationMetadata): array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return ['profiles' => [], 'notes' => ['cannot read json artifact']];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return ['profiles' => [], 'notes' => ['invalid json: '.$exception->getMessage()]];
        }

        if (!is_array($decoded)) {
            return ['profiles' => [], 'notes' => ['json root must be object/array']];
        }

        $context = $this->selectPrimaryContext($decoded);
        $contextPath = $context['path'];
        $contextValue = $context['value'];

        $endpoint = $this->resolveEndpoint($contextValue, $decoded, $validationMetadata);
        $metricSources = [];
        $ttfbMs = $this->metricFromContextOrRoot($contextValue, $decoded, 'ttfb_ms', $metricSources);
        $wallMs = $this->metricFromContextOrRoot($contextValue, $decoded, 'wall_ms', $metricSources) ?? 0.0;
        $cpuMs = $this->metricFromContextOrRoot($contextValue, $decoded, 'cpu_ms', $metricSources);
        $memMb = $this->metricFromContextOrRoot($contextValue, $decoded, 'mem_mb', $metricSources);

        $spans = $this->extractSpans($contextValue, $path, $contextPath);
        usort($spans, static fn (Span $a, Span $b): int => self::spanSortKey($a) <=> self::spanSortKey($b));

        $requestEvidenceNote = 'request-level metrics extracted from SPX JSON keys: '.implode(', ', $metricSources);

        return [
            'profiles' => [
                new RequestProfile(
                    endpoint: $endpoint,
                    ttfbMs: $ttfbMs,
                    wallMs: $wallMs,
                    cpuMs: $cpuMs,
                    memMb: $memMb,
                    spans: $spans,
                    evidence: [
                        new EvidenceRef(
                            source: 'spx',
                            file: $path,
                            lineRange: null,
                            recordId: 'json:'.$contextPath,
                            extractionNote: $requestEvidenceNote,
                        ),
                    ],
                ),
            ],
            'notes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $validationMetadata
     */
    private function resolveEndpoint(array $contextValue, array $root, array $validationMetadata): string
    {
        foreach (self::ENDPOINT_KEYS as $key) {
            $value = $contextValue[$key] ?? $root[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $run = $validationMetadata['run'] ?? [];
        if (is_array($run)) {
            $host = is_string($run['host'] ?? null) ? $run['host'] : 'unknown-host';
            $pid = is_int($run['pid'] ?? null) ? (string) $run['pid'] : '0';
            $runId = is_int($run['runid'] ?? null) ? (string) $run['runid'] : '0';

            return sprintf('spx://%s/%s/%s', $host, $pid, $runId);
        }

        return 'unknown_endpoint';
    }

    /**
     * @param array<string, mixed> $root
     * @return array{path:string,value:array<string,mixed>}
     */
    private function selectPrimaryContext(array $root): array
    {
        if (isset($root['requests']) && is_array($root['requests']) && $root['requests'] !== []) {
            foreach ($root['requests'] as $index => $request) {
                if (is_array($request)) {
                    return [
                        'path' => sprintf('root.requests.%d', $index),
                        'value' => $request,
                    ];
                }
            }
        }

        return ['path' => 'root', 'value' => $root];
    }

    /**
     * @param array<string, mixed> $node
     * @return list<Span>
     */
    private function extractSpans(array $node, string $file, string $basePath): array
    {
        $results = [];
        $queue = [[$basePath, $node]];

        while ($queue !== []) {
            [$path, $value] = array_shift($queue);
            if (!is_array($value)) {
                continue;
            }

            $label = $this->firstString($value, self::SPAN_LABEL_KEYS);
            $selfMs = $this->firstNumeric($value, self::SPAN_SELF_KEYS);
            $totalMs = $this->firstNumeric($value, self::SPAN_TOTAL_KEYS);

            if (($selfMs !== null || $totalMs !== null) && $label !== null) {
                $results[] = new Span(
                    type: 'php',
                    label: $label,
                    selfMs: $selfMs ?? 0.0,
                    totalMs: $totalMs ?? 0.0,
                    evidence: [
                        new EvidenceRef(
                            source: 'spx',
                            file: $file,
                            lineRange: null,
                            recordId: 'json:'.$path,
                            extractionNote: 'span metrics extracted from SPX JSON object keys',
                        ),
                    ],
                );
            }

            foreach ($value as $key => $child) {
                if (!is_array($child)) {
                    continue;
                }

                if (is_int($key)) {
                    $queue[] = [sprintf('%s.%d', $path, $key), $child];
                    continue;
                }

                $queue[] = [$path.'.'.$key, $child];
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractNumeric(array $payload, string $key): ?float
    {
        $value = $payload[$key] ?? null;
        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $root
     * @param list<string> $metricSources
     */
    private function metricFromContextOrRoot(array $context, array $root, string $key, array &$metricSources): ?float
    {
        if (is_numeric($context[$key] ?? null)) {
            $metricSources[] = sprintf('%s@context', $key);
            return (float) $context[$key];
        }

        if (is_numeric($root[$key] ?? null)) {
            $metricSources[] = sprintf('%s@root', $key);
            return (float) $root[$key];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function firstNumeric(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (is_numeric($payload[$key] ?? null)) {
                return (float) $payload[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private static function spanSortKey(Span $span): string
    {
        $recordId = '';
        if ($span->evidence !== []) {
            $recordId = (string) ($span->evidence[0]->recordId ?? '');
        }

        return sprintf('%s|%020.6f|%020.6f|%s', $span->label, $span->selfMs, $span->totalMs, $recordId);
    }
}
