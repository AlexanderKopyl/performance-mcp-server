<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

final readonly class AnalysisThresholds
{
    private const CONSERVATIVE_DEFAULTS = [
        'endpoint_wall_ms' => ['p0' => 2000.0, 'p1' => 1000.0, 'p2' => 400.0],
        'endpoint_ttfb_ms' => ['p0' => 1500.0, 'p1' => 800.0, 'p2' => 300.0],
        'span_self_ms' => ['p0' => 800.0, 'p1' => 300.0, 'p2' => 100.0],
        'span_total_ms' => ['p0' => 1500.0, 'p1' => 700.0, 'p2' => 250.0],
        'query_total_time_ms' => ['p0' => 10000.0, 'p1' => 3000.0, 'p2' => 1000.0],
    ];

    /**
     * @param array<string, array{p0:float,p1:float,p2:float}> $thresholds
     * @param array<string, string> $sources
     * @param list<string> $openQuestions
     */
    private function __construct(
        private array $thresholds,
        private array $sources,
        private array $openQuestions,
    ) {
    }

    /**
     * @param array<string, mixed>|null $input
     */
    public static function fromInput(?array $input): self
    {
        $thresholds = [];
        $sources = [];
        $openQuestions = [];

        foreach (self::CONSERVATIVE_DEFAULTS as $metric => $defaults) {
            $candidate = is_array($input) ? ($input[$metric] ?? null) : null;
            if (!is_array($candidate)) {
                $thresholds[$metric] = $defaults;
                $sources[$metric] = 'default_conservative';
                $openQuestions[] = sprintf(
                    'OPEN_QUESTION: provide custom thresholds for "%s" to replace conservative defaults.',
                    $metric,
                );
                continue;
            }

            $p0 = self::toFloat($candidate['p0'] ?? null);
            $p1 = self::toFloat($candidate['p1'] ?? null);
            $p2 = self::toFloat($candidate['p2'] ?? null);

            if ($p0 === null || $p1 === null || $p2 === null || !($p0 >= $p1 && $p1 >= $p2 && $p2 >= 0.0)) {
                $thresholds[$metric] = $defaults;
                $sources[$metric] = 'default_conservative';
                $openQuestions[] = sprintf(
                    'OPEN_QUESTION: threshold set for "%s" is missing or invalid (require p0 >= p1 >= p2 >= 0).',
                    $metric,
                );
                continue;
            }

            $thresholds[$metric] = ['p0' => $p0, 'p1' => $p1, 'p2' => $p2];
            $sources[$metric] = 'configured';
        }

        sort($openQuestions);

        return new self($thresholds, $sources, array_values(array_unique($openQuestions)));
    }

    public function severityFor(string $metric, float $value): ?string
    {
        $band = $this->thresholds[$metric] ?? null;
        if ($band === null) {
            return null;
        }

        if ($value >= $band['p0']) {
            return 'P0';
        }
        if ($value >= $band['p1']) {
            return 'P1';
        }
        if ($value >= $band['p2']) {
            return 'P2';
        }

        return null;
    }

    /**
     * @return array<string, array{p0:float,p1:float,p2:float,source:string}>
     */
    public function table(): array
    {
        $table = [];
        foreach ($this->thresholds as $metric => $band) {
            $table[$metric] = [
                'p0' => $band['p0'],
                'p1' => $band['p1'],
                'p2' => $band['p2'],
                'source' => $this->sources[$metric] ?? 'default_conservative',
            ];
        }

        ksort($table);

        return $table;
    }

    /**
     * @return list<string>
     */
    public function openQuestions(): array
    {
        return $this->openQuestions;
    }

    private static function toFloat(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        return (float) $value;
    }
}
