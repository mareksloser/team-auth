<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Jediné místo, kde se skládají claimy. Používá ho access token, id_token i /userinfo.
 *
 * roles        – role uživatele v aplikaci, pro kterou je token vydán ("coach", "coach@u11")
 * global_roles – role napříč aplikacemi ("superadmin")
 * teams        – [{slug, name, membership}]
 */
final class ClaimsProvider
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @param string[] $scopes
     *
     * @return array<string, mixed>|null null = uživatel neexistuje nebo je deaktivovaný
     */
    public function forSubject(string $sub, ?string $clientId, array $scopes): ?array
    {
        $user = $this->users->findActiveByUuid($sub);

        return null === $user ? null : $this->forUser($user, $clientId, $scopes);
    }

    /**
     * @param string[] $scopes
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user, ?string $clientId, array $scopes): array
    {
        $claims = [];

        if (\in_array('profile', $scopes, true)) {
            $claims['name'] = $user->getName();
            $claims['preferred_username'] = $user->getEmail();
        }

        if (\in_array('email', $scopes, true)) {
            $claims['email'] = $user->getEmail();
            $claims['email_verified'] = $user->isEmailVerified();
        }

        if (\in_array('teams', $scopes, true)) {
            $claims['teams'] = [];
            foreach ($user->getTeams() as $membership) {
                $claims['teams'][] = [
                    'slug' => $membership->getTeam()->getSlug(),
                    'name' => $membership->getTeam()->getName(),
                    'membership' => $membership->getMembership()->value,
                ];
            }
        }

        if (\in_array('roles', $scopes, true)) {
            $appRoles = [];
            $globalRoles = [];
            foreach ($user->getRoleAssignments() as $assignment) {
                $role = $assignment->getRole();
                if ($role->isGlobal()) {
                    $globalRoles[] = $role->getSlug();
                } elseif (null !== $clientId && $role->getClientId() === $clientId) {
                    $appRoles[] = $assignment->toClaim();
                }
            }
            $claims['roles'] = array_values(array_unique($appRoles));
            $claims['global_roles'] = array_values(array_unique($globalRoles));
        }

        return $claims;
    }
}
