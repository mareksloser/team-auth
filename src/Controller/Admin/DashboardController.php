<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\ClientAdmin;
use App\Admin\TeamAdmin;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class DashboardController extends AdminController
{
    private const AUDIT_PAGE = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    public function index(TeamAdmin $teams, ClientAdmin $clients): Response
    {
        $recent = $this->em->getRepository(AuditLog::class)->findBy([], ['id' => 'DESC'], 12);

        return $this->render('admin/dashboard.html.twig', [
            'userCount' => $this->users->count([]),
            'activeCount' => $this->users->count(['active' => true]),
            'teamCount' => \count($teams->all()),
            'clientCount' => \count($clients->all()),
            'recent' => $recent,
            'people' => $this->users->mapByUuids(array_map(static fn (AuditLog $l) => $l->getUserUuid(), $recent)),
        ]);
    }

    #[Route('/audit', name: 'admin_audit', methods: ['GET'])]
    public function audit(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $logs = $this->em->getRepository(AuditLog::class)->findBy([], ['id' => 'DESC'], self::AUDIT_PAGE + 1, ($page - 1) * self::AUDIT_PAGE);
        $hasNext = \count($logs) > self::AUDIT_PAGE;
        $logs = \array_slice($logs, 0, self::AUDIT_PAGE);

        $uuids = [];
        foreach ($logs as $log) {
            $uuids[] = $log->getUserUuid();
            $uuids[] = $log->getContext()['target'] ?? null;
        }

        return $this->render('admin/audit/index.html.twig', [
            'logs' => $logs,
            'people' => $this->users->mapByUuids($uuids),
            'page' => $page,
            'hasNext' => $hasNext,
        ]);
    }
}
