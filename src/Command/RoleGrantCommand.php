<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AppRole;
use App\Entity\TeamCategory;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'club:role:grant',
    description: 'Přidělí roli v aplikaci. Příklad: club:role:grant trener@klub.cz mvr-web coach --team=u11',
)]
final class RoleGrantCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('client', InputArgument::REQUIRED, 'client_id aplikace, nebo "*" pro globální roli')
            ->addArgument('role', InputArgument::REQUIRED, 'Např. coach, player, admin')
            ->addOption('team', null, InputOption::VALUE_REQUIRED, 'Omezit roli na tým (slug)')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Roli místo přidělení odebrat');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = $this->users->findOneByEmail((string) $input->getArgument('email'));
        if (null === $user) {
            $io->error('Uživatel neexistuje.');

            return Command::FAILURE;
        }

        $clientId = '*' === $input->getArgument('client') ? null : (string) $input->getArgument('client');
        $slug = strtolower((string) $input->getArgument('role'));

        $team = null;
        if (null !== $input->getOption('team')) {
            $team = $this->em->getRepository(TeamCategory::class)->findOneBy(['slug' => $input->getOption('team')]);
            if (null === $team) {
                $io->error('Tým neexistuje.');

                return Command::FAILURE;
            }
        }

        $role = $this->em->getRepository(AppRole::class)->findOneBy(['clientId' => $clientId, 'slug' => $slug]);

        if ($input->getOption('revoke')) {
            if (null !== $role) {
                $user->revoke($role, $team);
                $this->em->flush();
            }
            $io->success('Role odebrána.');

            return Command::SUCCESS;
        }

        if (null === $role) {
            $role = new AppRole($clientId, $slug);
            $this->em->persist($role);
        }

        $claim = $user->grant($role, $team)->toClaim();
        $this->em->flush();
        $io->success(sprintf('%s → %s: %s', $user->getEmail(), $clientId ?? 'globální', $claim));

        return Command::SUCCESS;
    }
}
