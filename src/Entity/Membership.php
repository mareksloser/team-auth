<?php

declare(strict_types=1);

namespace App\Entity;

enum Membership: string
{
    case Player = 'player';
    case Coach = 'coach';
    case Parent = 'parent';
    case Staff = 'staff';
}
