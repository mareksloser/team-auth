<?php

declare(strict_types=1);

namespace App\Portal;

/** One application tile in the club portal, as returned by GET /api/apps. */
final class PortalApp
{
    public const ACCESS_ROLE = 'role';
    public const ACCESS_PUBLIC = 'public';
    public const ACCESS_SUPERADMIN = 'superadmin';

    /**
     * @param list<array{role: string, team: ?string, teamName: ?string}> $roles
     * @param self::ACCESS_* $access why the user sees the app
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $url,
        public readonly ?string $logoUrl,
        public readonly ?string $accentColor,
        public readonly array $roles,
        public readonly string $access,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'clientId' => $this->clientId,
            'name' => $this->name,
            'description' => $this->description,
            'url' => $this->url,
            'logoUrl' => $this->logoUrl,
            'accentColor' => $this->accentColor,
            'roles' => $this->roles,
            'access' => $this->access,
        ];
    }
}
