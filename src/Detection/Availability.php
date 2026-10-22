<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

enum Availability: string
{
    case Available = 'available';
    case NotDetected = 'not_detected';
    case Disabled = 'disabled';
}
