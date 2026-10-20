<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

/**
 * The server could not be reached, or could not give its status: its other collectors would fail
 * the same way, and asking again at each poll would cost the same wait.
 */
final class UnreachableServerException extends \RuntimeException {}
