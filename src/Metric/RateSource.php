<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

enum RateSource
{
    case Delta;
    case SinceStart;
    case Unavailable;
    /** No previous sample to compare with yet: the next refresh gives the value. */
    case Pending;
}
