<?php

declare(strict_types=1);

namespace App\Branding;

use App\Entity\ClientBranding;
use App\Security\OAuthReturn;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Which application the user is signing in to, for the login and two-factor pages:
 * the client_id of the authorize request that sent them here (see OAuthReturn).
 */
final class LoginBranding
{
    /** @var array{client: ?ClientInterface, brand: ?ClientBranding, vars: array<string, string>}|null */
    private ?array $current = null;

    public function __construct(
        private readonly RequestStack $requests,
        private readonly ClientManagerInterface $clients,
        private readonly BrandingService $branding,
        private readonly OAuthReturn $return,
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
        $clientId = null !== $request && $request->hasSession() ? OAuthReturn::clientId($this->return->pending($request->getSession())) : null;
        $client = null !== $clientId ? $this->clients->find($clientId) : null;

        return null !== $client && $client->isActive() ? $client : null;
    }
}
