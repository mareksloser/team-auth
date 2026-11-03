<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\AttributesBasedUserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** The Symfony OIDC token handler passes all claims here as $attributes. */
final class ClubUserProvider implements AttributesBasedUserProviderInterface
{
    public function loadUserByIdentifier(string $identifier, array $attributes = []): UserInterface
    {
        return ClubUser::fromClaims($identifier, $attributes);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        throw new UnsupportedUserException('Stateless firewall – refresh is not used.');
    }

    public function supportsClass(string $class): bool
    {
        return ClubUser::class === $class;
    }
}
