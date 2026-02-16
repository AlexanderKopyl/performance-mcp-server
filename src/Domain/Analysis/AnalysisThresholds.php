<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

use InvalidArgumentException;

final readonly class AnalysisThresholds
{
    private const CONSERVATIVE_DEFAULTS = [
        'endpoint_wall_ms' => ['P0' => 2000.0, 'P1' => 1000.0, 'P2' => 400.0],
        'endpoint_ttfb_ms' => ['P0' => 1500.0, 'P1' => 800.0, 'P2' => 300.0],
        'span_self_ms' => ['P0' => 800.0, 'P1' => 300.0, 'P2' => 100.0],
        'span_total_ms' => ['P0' => 1500.0, 'P1' => 700.0, 'P2' => 250.0],
        'query_total_time_ms' => ['P0' => 10000.0, 'P1' => 3000.0, 'P2' => 1000.0],
    ];

    /**
     * @param array<string, array{P0:float,P1:float,P2:float}> $thresholds
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
        $thresholds = self::CONSERVATIVE_DEFAULTS;
        $sources = [];
        $openQuestions = self::buildThresholdOpenQuestions();

        foreach (array_keys(self::CONSERVATIVE_DEFAULTS) as $metric) {
            $sources[$metric] = 'default_conservative';
        }

        if ($input === null) {
            return new self($thresholds, $sources, $openQuestions);
        }

        $openQuestions = [];
        $errors = [];

        foreach ($input as $metric => $candidate) {
            if (!is_string($metric) || !isset(self::CONSERVATIVE_DEFAULTS[$metric])) {
                $errors[] = sprintf(
                    'params.thresholds has unsupported metric "%s"; allowed metrics: %s',
                    is_string($metric) ? $metric : gettype($metric),
                    implode(', ', array_keys(self::CONSERVATIVE_DEFAULTS)),
                );
                continue;
            }

            if (!is_array($candidate)) {
                $errors[] = sprintf('params.thresholds.%s must be an object with keys "P0", "P1", "P2"', $metric);
                continue;
            }

            $allowedKeys = ['P0', 'P1', 'P2'];
            foreach ($candidate as $key => $_) {
                if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
                    $errors[] = sprintf(
                        'params.thresholds.%s has unsupported key "%s"; allowed keys: P0, P1, P2',
                        $metric,
                        is_string($key) ? $key : gettype($key),
                    );
                }
            }

            $p0 = self::toPositiveInt($candidate['P0'] ?? null);
            $p1 = self::toPositiveInt($candidate['P1'] ?? null);
            $p2 = self::toPositiveInt($candidate['P2'] ?? null);
            if ($p0 === null || $p1 === null || $p2 === null) {
                $errors[] = sprintf(
                    'params.thresholds.%s must define positive integers for P0, P1, P2',
                    $metric,
                );
                continue;
            }

            if (!($p0 >= $p1 && $p1 >= $p2)) {
                $errors[] = sprintf(
                    'params.thresholds.%s must satisfy P0 >= P1 >= P2',
                    $metric,
                );
                continue;
            }

            $thresholds[$metric] = ['P0' => (float) $p0, 'P1' => (float) $p1, 'P2' => (float) $p2];
            $sources[$metric] = 'configured';
        }

        if ($errors !== []) {
            sort($errors);
            throw new InvalidArgumentException('Invalid params.thresholds: '.implode('; ', $errors));
        }

        return new self($thresholds, $sources, $openQuestions);
    }

    public function severityFor(string $metric, float $value): ?string
    {
        $band = $this->thresholds[$metric] ?? null;
        if ($band === null) {
            return null;
        }

        if ($value >= $band['P0']) {
            return 'P0';
        }
        if ($value >= $band['P1']) {
            return 'P1';
        }
        if ($value >= $band['P2']) {
            return 'P2';
        }

        return null;
    }

    /**
     * @return array<string, array{P0:float,P1:float,P2:float,source:string}>
     */
    public function table(): array
    {
        $table = [];
        foreach ($this->thresholds as $metric => $band) {
            $table[$metric] = [
                'P0' => $band['P0'],
                'P1' => $band['P1'],
                'P2' => $band['P2'],
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

    private static function toPositiveInt(mixed $value): ?int
    {
        if (!is_int($value) || $value <= 0) {
            return null;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function buildThresholdOpenQuestions(): array
    {
        $questions = [];

        foreach (array_keys(self::CONSERVATIVE_DEFAULTS) as $metric) {
            $questions[] = sprintf(
                'OPEN_QUESTION: provide params.thresholds.%s as {"P0":int,"P1":int,"P2":int} to replace conservative defaults.',
                $metric,
            );
        }

        sort($questions);

        return $questions;
    }
}
