<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\AttributesBasedUserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Symfony OIDC token handler sem předá všechny claimy jako $attributes. */
final class ClubUserProvider implements AttributesBasedUserProviderInterface
{
    public function loadUserByIdentifier(string $identifier, array $attributes = []): UserInterface
    {
        return ClubUser::fromClaims($identifier, $attributes);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        throw new UnsupportedUserException('Stateless firewall – refresh se nepoužívá.');
    }

    public function supportsClass(string $class): bool
    {
        return ClubUser::class === $class;
    }
}
