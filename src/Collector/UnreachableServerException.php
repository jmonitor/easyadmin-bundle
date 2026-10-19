<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

/**
 * The connection to the server could not be opened: its other collectors would fail the same way.
 */
final class UnreachableServerException extends \RuntimeException {}
