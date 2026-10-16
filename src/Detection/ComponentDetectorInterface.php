<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\EasyAdminBundle\Component;

interface ComponentDetectorInterface
{
    public function component(): Component;

    public function detect(): Detection;
}
