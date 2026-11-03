<?php

declare(strict_types=1);

namespace App\Admin;

use App\Audit\AuditLogger;
use App\Branding\BrandingService;
use App\Entity\AppRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Manages OAuth clients (applications) and their role catalogue.
 * Secrets are stored hashed, same as league:oauth2-server:create-client, and shown in plain text only once.
 */
final class ClientAdmin
{
    public const GRANTS = [
        OAuth2Grants::AUTHORIZATION_CODE => 'Authorization Code (přihlášení uživatele)',
        OAuth2Grants::REFRESH_TOKEN => 'Refresh Token (obnova přihlášení)',
        OAuth2Grants::CLIENT_CREDENTIALS => 'Client Credentials (server ↔ server, jen důvěrný klient)',
    ];

    /** @param string[] $supportedScopes */
    public function __construct(
        private readonly ClientManagerInterface $clients,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'league.oauth2_server.password_hasher')]
        private readonly PasswordHasherInterface $secretHasher,
        private readonly AuditLogger $audit,
        private readonly BrandingService $branding,
        private readonly array $supportedScopes,
    ) {
    }

    /** @return string[] */
    public function supportedScopes(): array
    {
        return $this->supportedScopes;
    }

    /** @return ClientInterface[] */
    public function all(): array
    {
        $clients = $this->clients->list(null);
        usort($clients, static fn (ClientInterface $a, ClientInterface $b): int => strcasecmp($a->getName(), $b->getName()));

        return $clients;
    }

    public function find(string $identifier): ?ClientInterface
    {
        return $this->clients->find($identifier);
    }

    /**
     * @param string[] $grants
     * @param string[] $scopes
     *
     * @return array{0: ClientInterface, 1: ?string} client and plain secret (confidential clients only)
     */
    public function create(string $name, string $identifier, bool $confidential, string $redirectUris, array $grants, array $scopes, User $actor): array
    {
        $identifier = strtolower(trim($identifier));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,79}$/', $identifier)) {
            throw new AdminException('client_id smí obsahovat a-z, 0-9, pomlčku a podtržítko (2–80 znaků).');
        }
        if (null !== $this->clients->find($identifier)) {
            throw new AdminException('Klient s tímto client_id už existuje.');
        }

        $plainSecret = $confidential ? bin2hex(random_bytes(32)) : null;
        $client = new Client($this->validName($name), $identifier, null === $plainSecret ? null : $this->secretHasher->hash($plainSecret));
        $this->apply($client, $redirectUris, $grants, $scopes, true);

        $this->clients->save($client);
        $this->audit->log('admin.client_create', $actor->getUuid(), $identifier, ['confidential' => $confidential]);

        return [$client, $plainSecret];
    }

    /**
     * @param string[] $grants
     * @param string[] $scopes
     */
    public function update(ClientInterface $client, string $name, string $redirectUris, array $grants, array $scopes, bool $active, User $actor): void
    {
        $client->setName($this->validName($name));
        $this->apply($client, $redirectUris, $grants, $scopes, $active);
        $this->clients->save($client);
        $this->audit->log('admin.client_update', $actor->getUuid(), $client->getIdentifier(), ['active' => $active]);
    }

    public function regenerateSecret(ClientInterface $client, User $actor): string
    {
        if (!$client->isConfidential()) {
            throw new AdminException('Veřejný klient (SPA) secret nemá.');
        }
        $plain = bin2hex(random_bytes(32));
        $client->setSecret($this->secretHasher->hash($plain));
        $this->clients->save($client);
        $this->audit->log('admin.client_secret', $actor->getUuid(), $client->getIdentifier());

        return $plain;
    }

    public function delete(ClientInterface $client, User $actor): void
    {
        $identifier = $client->getIdentifier();
        foreach ($this->roles($identifier) as $role) {
            $this->em->remove($role); // role assignments are removed via ON DELETE CASCADE
        }
        $this->em->flush();
        $this->branding->delete($identifier);
        $this->clients->remove($client);
        $this->audit->log('admin.client_delete', $actor->getUuid(), $identifier);
    }

    /** @return AppRole[] */
    public function roles(string $clientId): array
    {
        return $this->em->getRepository(AppRole::class)->findBy(['clientId' => $clientId], ['slug' => 'ASC']);
    }

    /** @return AppRole[] all roles (global first) – for the grant select */
    public function allRoles(): array
    {
        $roles = $this->em->getRepository(AppRole::class)->findAll();
        usort($roles, static fn (AppRole $a, AppRole $b): int => [$a->getClientId() ?? '', $a->getSlug()] <=> [$b->getClientId() ?? '', $b->getSlug()]);

        return $roles;
    }

    public function addRole(?string $clientId, string $slug, ?string $description, User $actor): AppRole
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', $slug)) {
            throw new AdminException('Název role smí obsahovat a-z, 0-9, pomlčku a podtržítko (max. 50 znaků).');
        }
        if (null !== $clientId && null === $this->clients->find($clientId)) {
            throw new AdminException('Neznámá aplikace.');
        }
        if (null !== $this->em->getRepository(AppRole::class)->findOneBy(['clientId' => $clientId, 'slug' => $slug])) {
            throw new AdminException('Tato role už existuje.');
        }

        $description = trim((string) $description);
        $role = new AppRole($clientId, $slug, '' === $description ? null : mb_substr($description, 0, 255));
        $this->em->persist($role);
        $this->em->flush();
        $this->audit->log('admin.role_create', $actor->getUuid(), $clientId, ['role' => $slug]);

        return $role;
    }

    public function deleteRole(AppRole $role, User $actor): void
    {
        if ($role->isGlobal() && 'superadmin' === $role->getSlug()) {
            throw new AdminException('Roli superadmin smazat nelze.');
        }
        $this->em->remove($role);
        $this->em->flush();
        $this->audit->log('admin.role_delete', $actor->getUuid(), $role->getClientId(), ['role' => $role->getSlug()]);
    }

    /**
     * @param string[] $grants
     * @param string[] $scopes
     */
    private function apply(ClientInterface $client, string $redirectUris, array $grants, array $scopes, bool $active): void
    {
        $grants = array_values(array_intersect(array_keys(self::GRANTS), $grants));
        $scopes = array_values(array_intersect($this->supportedScopes, $scopes));
        $uris = $this->parseRedirectUris($redirectUris);

        if ([] === $grants) {
            throw new AdminException('Vyber alespoň jeden typ přihlášení (grant).');
        }
        if (\in_array(OAuth2Grants::CLIENT_CREDENTIALS, $grants, true) && !$client->isConfidential()) {
            throw new AdminException('Client Credentials vyžaduje důvěrného klienta se secretem.');
        }
        if (\in_array(OAuth2Grants::AUTHORIZATION_CODE, $grants, true) && [] === $uris) {
            throw new AdminException('Authorization Code vyžaduje alespoň jednu redirect URI.');
        }

        $client->setRedirectUris(...$uris);
        $client->setGrants(...array_map(static fn (string $g): Grant => new Grant($g), $grants));
        $client->setScopes(...array_map(static fn (string $s): Scope => new Scope($s), $scopes));
        $client->setActive($active);
        $client->setAllowPlainTextPkce(false);
    }

    /** @return RedirectUri[] */
    private function parseRedirectUris(string $text): array
    {
        $uris = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $parts = parse_url($line);
            $host = strtolower($parts['host'] ?? '');
            $isLocal = \in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.test');
            if (!isset($parts['scheme']) || ('https' !== $parts['scheme'] && !('http' === $parts['scheme'] && $isLocal))) {
                throw new AdminException(sprintf('„%s“: redirect URI musí být https (http jen pro localhost / .test).', $line));
            }
            if (isset($parts['fragment'])) {
                throw new AdminException(sprintf('„%s“: redirect URI nesmí obsahovat #fragment.', $line));
            }
            try {
                $uris[$line] = new RedirectUri($line);
            } catch (\RuntimeException $e) {
                throw new AdminException(sprintf('„%s“ není platná URI.', $line), 0, $e);
            }
        }

        return array_values($uris);
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 128) {
            throw new AdminException('Název aplikace je povinný (max. 128 znaků).');
        }

        return $name;
    }
}
