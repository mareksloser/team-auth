<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\AdminException;
use App\Admin\ClientAdmin;
use App\Entity\AppRole;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/apps')]
final class ClientController extends AdminController
{
    public function __construct(
        private readonly ClientAdmin $admin,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_clients', methods: ['GET'])]
    public function index(): Response
    {
        $clients = $this->admin->all();
        $roleCounts = [];
        foreach ($clients as $client) {
            $roleCounts[$client->getIdentifier()] = \count($this->admin->roles($client->getIdentifier()));
        }

        return $this->render('admin/clients/index.html.twig', [
            'clients' => $clients,
            'roleCounts' => $roleCounts,
            'globalRoles' => $this->em->getRepository(AppRole::class)->findBy(['clientId' => null], ['slug' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'admin_client_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = [
            'name' => '', 'identifier' => '', 'confidential' => false, 'redirect_uris' => '',
            'grants' => [OAuth2Grants::AUTHORIZATION_CODE, OAuth2Grants::REFRESH_TOKEN],
            'scopes' => $this->admin->supportedScopes(),
        ];

        if ($request->isMethod('POST')) {
            $this->guard($request);
            $form = $this->readForm($request) + ['identifier' => (string) $request->request->get('identifier'), 'confidential' => 'confidential' === $request->request->get('type')];
            try {
                [$client, $secret] = $this->admin->create($form['name'], $form['identifier'], $form['confidential'], $form['redirect_uris'], $form['grants'], $form['scopes'], $this->actor());
                if (null !== $secret) {
                    return $this->showSecret($client, $secret, 'Aplikace vytvořena');
                }
                $this->addFlash('success', 'Aplikace vytvořena. Teď jí nadefinuj role.');

                return $this->redirectToRoute('admin_client_edit', ['identifier' => $client->getIdentifier()]);
            } catch (AdminException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/clients/form.html.twig', $this->formView(null, $form));
    }

    #[Route('/{identifier}', name: 'admin_client_edit', methods: ['GET', 'POST'])]
    public function edit(string $identifier, Request $request): Response
    {
        $client = $this->client($identifier);
        $form = [
            'name' => $client->getName(),
            'redirect_uris' => implode("\n", array_map('strval', $client->getRedirectUris())),
            'grants' => array_map('strval', $client->getGrants()),
            'scopes' => array_map('strval', $client->getScopes()),
            'active' => $client->isActive(),
        ];

        if ($request->isMethod('POST')) {
            $this->guard($request);
            $form = $this->readForm($request) + ['active' => $request->request->getBoolean('active')];
            try {
                $this->admin->update($client, $form['name'], $form['redirect_uris'], $form['grants'], $form['scopes'], $form['active'], $this->actor());
                $this->addFlash('success', 'Aplikace uložena.');

                return $this->redirectToRoute('admin_client_edit', ['identifier' => $identifier]);
            } catch (AdminException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/clients/form.html.twig', $this->formView($client, $form) + [
            'roles' => $this->admin->roles($identifier),
        ]);
    }

    #[Route('/{identifier}/secret', name: 'admin_client_secret', methods: ['POST'])]
    public function secret(string $identifier, Request $request): Response
    {
        $this->guard($request);
        $client = $this->client($identifier);
        try {
            return $this->showSecret($client, $this->admin->regenerateSecret($client, $this->actor()), 'Nový secret');
        } catch (AdminException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_client_edit', ['identifier' => $identifier]);
        }
    }

    #[Route('/{identifier}/delete', name: 'admin_client_delete', methods: ['POST'])]
    public function delete(string $identifier, Request $request): Response
    {
        return $this->handle($request, fn () => $this->admin->delete($this->client($identifier), $this->actor()), 'Aplikace smazána.', 'admin_clients');
    }

    /** client_id "_global" = globální role */
    #[Route('/{identifier}/roles', name: 'admin_client_role_add', methods: ['POST'])]
    public function addRole(string $identifier, Request $request): Response
    {
        $clientId = '_global' === $identifier ? null : $this->client($identifier)->getIdentifier();

        return $this->handle($request, fn () => $this->admin->addRole(
            $clientId,
            (string) $request->request->get('slug'),
            (string) $request->request->get('description'),
            $this->actor(),
        ), 'Role přidána.', ...$this->back($clientId));
    }

    #[Route('/roles/{id<\d+>}/delete', name: 'admin_client_role_delete', methods: ['POST'])]
    public function deleteRole(AppRole $role, Request $request): Response
    {
        return $this->handle($request, fn () => $this->admin->deleteRole($role, $this->actor()), 'Role smazána.', ...$this->back($role->getClientId()));
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function back(?string $clientId): array
    {
        return null === $clientId ? ['admin_clients', []] : ['admin_client_edit', ['identifier' => $clientId]];
    }

    private function client(string $identifier): ClientInterface
    {
        return $this->admin->find($identifier) ?? throw new NotFoundHttpException('Aplikace neexistuje.');
    }

    /** @return array{name: string, redirect_uris: string, grants: string[], scopes: string[]} */
    private function readForm(Request $request): array
    {
        $r = $request->request;

        return [
            'name' => (string) $r->get('name'),
            'redirect_uris' => (string) $r->get('redirect_uris'),
            'grants' => array_map('strval', $r->all('grants')),
            'scopes' => array_map('strval', $r->all('scopes')),
        ];
    }

    /** @param array<string, mixed> $form */
    private function formView(?ClientInterface $client, array $form): array
    {
        return [
            'client' => $client,
            'form' => $form,
            'grantOptions' => ClientAdmin::GRANTS,
            'scopeOptions' => $this->admin->supportedScopes(),
            'issuer' => $this->getParameter('app.oidc_issuer'),
        ];
    }

    private function showSecret(ClientInterface $client, string $secret, string $title): Response
    {
        $response = $this->render('admin/clients/secret.html.twig', ['client' => $client, 'secret' => $secret, 'title' => $title]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
