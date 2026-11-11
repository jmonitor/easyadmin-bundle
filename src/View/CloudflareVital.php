<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;

/**
 * A Web Vital at the 75th percentile, rated against the thresholds published by Google.
 */
final readonly class CloudflareVital
{
    /** [good below, poor from], in seconds, or as a score for CLS */
    private const THRESHOLDS = [
        'LCP' => [2.5, 4.0],
        'INP' => [0.2, 0.5],
        'CLS' => [0.1, 0.25],
        'TTFB' => [0.8, 1.8],
    ];

    public ?Badge $rating;

    /**
     * @param ?float $value seconds, or the score for CLS
     */
    public function __construct(
        public string $label,
        public ?float $value,
        public bool $isDuration = true,
    ) {
        [$good, $poor] = self::THRESHOLDS[$label] ?? [null, null];

        $this->rating = match (true) {
            $value === null || $good === null => null,
            $value < $good => new Badge(BadgeStyle::Success, 'Good'),
            $value < $poor => new Badge(BadgeStyle::Warning, 'Needs improvement'),
            default => new Badge(BadgeStyle::Danger, 'Poor'),
        };
    }
}
