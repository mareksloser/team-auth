<?php

declare(strict_types=1);

namespace App\Branding;

use App\Entity\ClientBranding;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Which application the user is signing in to, for the login and two-factor pages.
 * The firewall saved the /oauth/authorize URL as the target path before redirecting to /login;
 * its client_id identifies the application.
 */
final class LoginBranding
{
    use TargetPathTrait;

    /** @var array{client: ?ClientInterface, brand: ?ClientBranding, vars: array<string, string>}|null */
    private ?array $current = null;

    public function __construct(
        private readonly RequestStack $requests,
        private readonly ClientManagerInterface $clients,
        private readonly BrandingService $branding,
    ) {
    }

    /** @return array{client: ?ClientInterface, brand: ?ClientBranding, vars: array<string, string>} */
    public function current(): array
    {
        if (null !== $this->current) {
            return $this->current;
        }
        $client = $this->client();
        $brand = null !== $client ? $this->branding->find($client->getIdentifier()) : null;

        return $this->current = ['client' => $client, 'brand' => $brand, 'vars' => BrandingService::cssVariables($brand)];
    }

    private function client(): ?ClientInterface
    {
        $request = $this->requests->getCurrentRequest();
        $target = null !== $request && $request->hasSession() ? $this->getTargetPath($request->getSession(), 'main') : null;
        if (null === $target || !str_contains((string) parse_url($target, \PHP_URL_PATH), '/oauth/authorize')) {
            return null;
        }
        parse_str((string) parse_url($target, \PHP_URL_QUERY), $query);
        $clientId = $query['client_id'] ?? null;
        $client = \is_string($clientId) && '' !== $clientId ? $this->clients->find($clientId) : null;

        return null !== $client && $client->isActive() ? $client : null;
    }
}
