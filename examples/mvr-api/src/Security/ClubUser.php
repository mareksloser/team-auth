<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Uživatel sestavený čistě z claimů tokenu – aplikace nemusí mít vlastní tabulku uživatelů.
 * Pro FK (např. player_marker_views.user_id) ukládej `sub` (UUID) jako CHAR(36).
 */
final class ClubUser implements UserInterface
{
    /**
     * @param string[]                                                   $roles       "coach", "coach@u11"
     * @param string[]                                                   $globalRoles "superadmin"
     * @param array<int, array{slug: string, name: string, membership: string}> $teams
     */
    public function __construct(
        public readonly string $sub,
        public readonly string $name,
        public readonly ?string $email,
        public readonly array $roles,
        public readonly array $globalRoles,
        public readonly array $teams,
    ) {
    }

    /** @param array<string, mixed> $claims */
    public static function fromClaims(string $sub, array $claims): self
    {
        return new self(
            sub: $sub,
            name: (string) ($claims['name'] ?? ''),
            email: isset($claims['email']) ? (string) $claims['email'] : null,
            roles: array_map('strval', (array) ($claims['roles'] ?? [])),
            globalRoles: array_map('strval', (array) ($claims['global_roles'] ?? [])),
            teams: (array) ($claims['teams'] ?? []),
        );
    }

    public function getUserIdentifier(): string
    {
        return $this->sub;
    }

    /** "coach@u11" → ROLE_COACH (tým kontroluj přes hasRole / voter) */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        foreach ([...$this->roles, ...$this->globalRoles] as $role) {
            $roles[] = 'ROLE_'.strtoupper(str_replace('-', '_', explode('@', $role)[0]));
        }

        return array_values(array_unique($roles));
    }

    public function hasRole(string $role, ?string $team = null): bool
    {
        if (\in_array('superadmin', $this->globalRoles, true)) {
            return true;
        }
        // role bez týmu platí pro všechny týmy
        if (\in_array($role, $this->roles, true)) {
            return true;
        }

        return null !== $team && \in_array($role.'@'.$team, $this->roles, true);
    }

    /** @return string[] slugy týmů, kde má uživatel danou roli (pro filtrování dotazů) */
    public function teamsWithRole(string $role): array
    {
        $teams = [];
        foreach ($this->roles as $claim) {
            [$slug, $team] = array_pad(explode('@', $claim, 2), 2, null);
            if ($slug === $role && null !== $team) {
                $teams[] = $team;
            }
        }

        return $teams;
    }

    public function eraseCredentials(): void
    {
    }
}
