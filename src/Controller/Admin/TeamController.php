<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\TeamAdmin;
use App\Entity\TeamCategory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/teams')]
final class TeamController extends AdminController
{
    public function __construct(private readonly TeamAdmin $admin)
    {
    }

    #[Route('', name: 'admin_teams', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/teams/index.html.twig', [
            'teams' => $this->admin->all(),
            'counts' => $this->admin->memberCounts(),
        ]);
    }

    #[Route('', name: 'admin_team_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        return $this->handle($request, fn () => $this->admin->create(
            (string) $request->request->get('slug'),
            (string) $request->request->get('name'),
            $this->actor(),
        ), 'Tým vytvořen.', 'admin_teams');
    }

    #[Route('/{id<\d+>}/rename', name: 'admin_team_rename', methods: ['POST'])]
    public function rename(TeamCategory $team, Request $request): Response
    {
        return $this->handle($request, fn () => $this->admin->rename($team, (string) $request->request->get('name'), $this->actor()),
            'Název uložen.', 'admin_teams');
    }

    #[Route('/{id<\d+>}/delete', name: 'admin_team_delete', methods: ['POST'])]
    public function delete(TeamCategory $team, Request $request): Response
    {
        return $this->handle($request, fn () => $this->admin->delete($team, $this->actor()), 'Tým smazán.', 'admin_teams');
    }
}
