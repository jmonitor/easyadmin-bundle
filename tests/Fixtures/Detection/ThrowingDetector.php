<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\Detection;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ComponentDetectorInterface;
use Jmonitor\EasyAdminBundle\Detection\Detection;

final class ThrowingDetector implements ComponentDetectorInterface
{
    public function __construct(
        private readonly Component $component,
        private readonly string $message,
    ) {}

    public function component(): Component
    {
        return $this->component;
    }

    public function detect(): Detection
    {
        throw new \InvalidArgumentException($this->message);
    }
}
