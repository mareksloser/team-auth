<?php

declare(strict_types=1);

namespace App\Twig;

use App\Device\TimeAgo;
use App\Device\UserAgent;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class DeviceExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('ago', static fn (\DateTimeInterface $time): string => TimeAgo::format($time, new \DateTimeImmutable())),
            new TwigFilter('device_name', UserAgent::describe(...)),
        ];
    }
}
