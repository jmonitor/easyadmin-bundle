<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

/**
 * Counts by label, each one drawn as a bar of its share of the total.
 */
final readonly class Breakdown
{
    public const OTHER = 'other';

    /**
     * @param list<BreakdownRow> $rows
     */
    public function __construct(
        public array $rows,
    ) {}

    /**
     * @param array<array-key, int>              $counts in display order
     * @param ?\Closure(string): string      $label  display name of a key
     * @param ?\Closure(string): ?BadgeStyle $style  colour of the bar of a key
     */
    public static function of(array $counts, ?\Closure $label = null, ?\Closure $style = null): self
    {
        $total = array_sum($counts);
        $rows = [];

        foreach ($counts as $key => $count) {
            $key = (string) $key;
            $name = match (true) {
                $key === self::OTHER => 'Other',
                $label !== null => $label($key),
                default => $key,
            };

            $rows[] = new BreakdownRow($name === '' ? 'Unknown' : $name, $count, $total > 0 ? $count / $total * 100 : 0.0, $style === null ? null : $style($key));
        }

        return new self($rows);
    }

    /**
     * The largest counts, the rest folded into "other".
     *
     * @param array<array-key, int> $counts
     * @param ?int                  $total  what a complete list adds up to: one that its source cut
     *                                      short misses the difference
     *
     * @return array<array-key, int>
     */
    public static function top(array $counts, int $limit = 5, ?int $total = null): array
    {
        arsort($counts);
        $top = array_slice($counts, 0, $limit, true);
        $rest = max($total ?? 0, array_sum($counts)) - array_sum($top);

        if ($rest > 0) {
            $top[self::OTHER] = ($top[self::OTHER] ?? 0) + $rest;
        }

        return $top;
    }
}
