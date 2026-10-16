<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

enum RateSource
{
    case Delta;
    case SinceStart;
    case Unavailable;
}
