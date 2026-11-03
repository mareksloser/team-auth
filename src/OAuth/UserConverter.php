<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\User as AppUser;
use League\Bundle\OAuth2ServerBundle\Converter\UserConverterInterface;
use League\Bundle\OAuth2ServerBundle\Entity\User as LeagueUser;
use League\OAuth2\Server\Entities\UserEntityInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** `sub` v tokenech = stabilní UUID místo e-mailu (e-mail se může změnit). */
final class UserConverter implements UserConverterInterface
{
    public function toLeague(?UserInterface $user): UserEntityInterface
    {
        $entity = new LeagueUser();
        if ($user instanceof AppUser) {
            $entity->setIdentifier($user->getUuid());
        } elseif (null !== $user) {
            $entity->setIdentifier($user->getUserIdentifier());
        }

        return $entity;
    }
}
