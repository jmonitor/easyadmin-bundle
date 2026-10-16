<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

enum BadgeStyle: string
{
    case Success = 'success';
    case Info = 'info';
    case Warning = 'warning';
    case Danger = 'danger';
    case Neutral = 'secondary';

    public function cssClass(): string
    {
        return 'text-bg-' . $this->value;
    }
}
