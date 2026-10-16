<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

final class ErrorReporting
{
    private const LEVELS = [
        'E_ERROR' => \E_ERROR,
        'E_WARNING' => \E_WARNING,
        'E_PARSE' => \E_PARSE,
        'E_NOTICE' => \E_NOTICE,
        'E_CORE_ERROR' => \E_CORE_ERROR,
        'E_CORE_WARNING' => \E_CORE_WARNING,
        'E_COMPILE_ERROR' => \E_COMPILE_ERROR,
        'E_COMPILE_WARNING' => \E_COMPILE_WARNING,
        'E_USER_ERROR' => \E_USER_ERROR,
        'E_USER_WARNING' => \E_USER_WARNING,
        'E_USER_NOTICE' => \E_USER_NOTICE,
        // the constant is deprecated since PHP 8.4
        'E_STRICT' => 2048,
        'E_RECOVERABLE_ERROR' => \E_RECOVERABLE_ERROR,
        'E_DEPRECATED' => \E_DEPRECATED,
        'E_USER_DEPRECATED' => \E_USER_DEPRECATED,
    ];

    /**
     * Gives the shortest of "E_ALL & ~A & ~B" and "A | B | C".
     */
    public static function describe(?int $level): ?string
    {
        if ($level === null) {
            return null;
        }

        if ($level === 0) {
            return '0';
        }

        if (($level & \E_ALL) === \E_ALL) {
            return 'E_ALL';
        }

        $enabled = array_keys(array_filter(self::LEVELS, static fn(int $bit): bool => ($level & $bit) !== 0));
        $expanded = $enabled === [] ? (string) $level : implode(' | ', $enabled);

        if (($level & ~\E_ALL) !== 0) {
            return $expanded;
        }

        $excluded = array_keys(array_filter(self::LEVELS, static fn(int $bit): bool => (\E_ALL & $bit) !== 0 && ($level & $bit) === 0));
        $compact = 'E_ALL & ~' . implode(' & ~', $excluded);

        return strlen($compact) < strlen($expanded) ? $compact : $expanded;
    }
}
