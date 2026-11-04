<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\AdminException;
use App\Admin\ClientAdmin;
use App\Admin\TeamAdmin;
use App\Admin\UserAdmin;
use App\Device\DeviceTracker;
use App\Entity\AppRole;
use App\Entity\Membership;
use App\Entity\TeamCategory;
use App\Entity\User;
use App\Entity\UserRole;
use App\Entity\UserTeam;
use App\Repository\UserRepository;
use App\Security\TwoFactor\TwoFactorSetup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/users')]
final class UserController extends AdminController
{
    private const PAGE = 50;

    public function __construct(
        private readonly UserAdmin $admin,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_users', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $found = $this->users->search($q, self::PAGE + 1, ($page - 1) * self::PAGE);

        return $this->render('admin/users/index.html.twig', [
            'users' => \array_slice($found, 0, self::PAGE),
            'q' => $q,
            'page' => $page,
            'hasNext' => \count($found) > self::PAGE,
        ]);
    }

    #[Route('/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = ['email' => '', 'name' => ''];

        if ($request->isMethod('POST')) {
            $this->guard($request);
            $data = ['email' => (string) $request->request->get('email'), 'name' => (string) $request->request->get('name')];
            try {
                $user = $this->admin->create($data['email'], $data['name'], (string) $request->request->get('password'), $this->actor());
                $this->addFlash('success', 'Uživatel vytvořen. Teď mu přiřaď tým a role.');

                return $this->redirectToRoute('admin_user_edit', ['id' => $user->getId()]);
            } catch (AdminException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/users/new.html.twig', ['data' => $data, 'minPassword' => UserAdmin::MIN_PASSWORD_LENGTH]);
    }

    #[Route('/{id<\d+>}', name: 'admin_user_edit', methods: ['GET'])]
    public function edit(User $user, TeamAdmin $teams, ClientAdmin $clients, DeviceTracker $devices): Response
    {
        $clientNames = [];
        foreach ($clients->all() as $client) {
            $clientNames[$client->getIdentifier()] = $client->getName();
        }

        $roleGroups = [];
        foreach ($clients->allRoles() as $role) {
            $label = $role->isGlobal() ? 'Globální' : ($clientNames[$role->getClientId()] ?? $role->getClientId());
            $roleGroups[$label][] = $role;
        }

        return $this->render('admin/users/edit.html.twig', [
            'user' => $user,
            'teams' => $teams->all(),
            'roleGroups' => $roleGroups,
            'clientNames' => $clientNames,
            'memberships' => Membership::cases(),
            'minPassword' => UserAdmin::MIN_PASSWORD_LENGTH,
            'devices' => $devices->activeDevices($user),
        ]);
    }

    #[Route('/{id<\d+>}/devices/logout', name: 'admin_user_devices_logout', methods: ['POST'])]
    public function signOutEverywhere(User $user, Request $request): Response
    {
        return $this->handle($request, fn () => $this->admin->signOutEverywhere($user, $this->actor()),
            'Uživatel je odhlášený ze všech zařízení.', 'admin_user_edit', ['id' => $user->getId()]);
    }

    #[Route('/{id<\d+>}/update', name: 'admin_user_update', methods: ['POST'])]
    public function update(User $user, Request $request): Response
    {
        $r = $request->request;

        return $this->handle($request, fn () => $this->admin->update(
            $user,
            (string) $r->get('email'),
            (string) $r->get('name'),
            $r->getBoolean('active'),
            $r->getBoolean('email_verified'),
            $this->actor(),
        ), 'Údaje uloženy.', 'admin_user_edit', ['id' => $user->getId()]);
    }

    #[Route('/{id<\d+>}/password', name: 'admin_user_password', methods: ['POST'])]
    public function password(User $user, Request $request): Response
    {
        return $this->handle($request, fn () => $this->admin->setPassword($user, (string) $request->request->get('password'), $this->actor()),
            'Heslo změněno.', 'admin_user_edit', ['id' => $user->getId()]);
    }

    /** For someone who lost both the phone and the backup codes. */
    #[Route('/{id<\d+>}/2fa/disable', name: 'admin_user_2fa_disable', methods: ['POST'])]
    public function disableTwoFactor(User $user, Request $request, TwoFactorSetup $setup): Response
    {
        return $this->handle($request, fn () => $setup->disableByAdmin($user, $this->actor()),
            'Dvoufázové ověření vypnuto. Uživatel se přihlásí jen heslem a může si ho zapnout znovu.', 'admin_user_edit', ['id' => $user->getId()]);
    }

    #[Route('/{id<\d+>}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function delete(User $user, Request $request): Response
    {
        $this->guard($request);
        try {
            $this->admin->delete($user, $this->actor());
            $this->addFlash('success', 'Uživatel smazán.');

            return $this->redirectToRoute('admin_users');
        } catch (AdminException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_user_edit', ['id' => $user->getId()]);
        }
    }

    #[Route('/{id<\d+>}/teams', name: 'admin_user_team_add', methods: ['POST'])]
    public function addTeam(User $user, Request $request): Response
    {
        return $this->handle($request, function () use ($user, $request): void {
            $team = $this->em->find(TeamCategory::class, $request->request->getInt('team'));
            $membership = Membership::tryFrom((string) $request->request->get('membership'));
            if (null === $team || null === $membership) {
                throw new AdminException('Vyber tým a typ členství.');
            }
            $this->admin->addTeam($user, $team, $membership, $this->actor());
        }, 'Tým přidán.', 'admin_user_edit', ['id' => $user->getId()]);
    }

    #[Route('/{id<\d+>}/teams/{membershipId<\d+>}/remove', name: 'admin_user_team_remove', methods: ['POST'])]
    public function removeTeam(User $user, int $membershipId, Request $request): Response
    {
        return $this->handle($request, function () use ($user, $membershipId): void {
            $membership = $this->em->find(UserTeam::class, $membershipId) ?? throw new AdminException('Členství neexistuje.');
            $this->admin->removeTeam($user, $membership, $this->actor());
        }, 'Tým odebrán.', 'admin_user_edit', ['id' => $user->getId()]);
    }

    #[Route('/{id<\d+>}/roles', name: 'admin_user_role_grant', methods: ['POST'])]
    public function grant(User $user, Request $request): Response
    {
        return $this->handle($request, function () use ($user, $request): void {
            $role = $this->em->find(AppRole::class, $request->request->getInt('role')) ?? throw new AdminException('Vyber roli.');
            $teamId = $request->request->getInt('team');
            $team = $teamId > 0 ? $this->em->find(TeamCategory::class, $teamId) : null;
            $this->admin->grant($user, $role, $team, $this->actor());
        }, 'Role přidělena.', 'admin_user_edit', ['id' => $user->getId()]);
    }

    #[Route('/{id<\d+>}/roles/{assignmentId<\d+>}/revoke', name: 'admin_user_role_revoke', methods: ['POST'])]
    public function revoke(User $user, int $assignmentId, Request $request): Response
    {
        return $this->handle($request, function () use ($user, $assignmentId): void {
            $assignment = $this->em->find(UserRole::class, $assignmentId) ?? throw new AdminException('Přiřazení neexistuje.');
            $this->admin->revoke($user, $assignment, $this->actor());
        }, 'Role odebrána.', 'admin_user_edit', ['id' => $user->getId()]);
    }
}
