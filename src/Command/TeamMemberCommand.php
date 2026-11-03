<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Membership;
use App\Entity\TeamCategory;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'club:team:add', description: 'Přidá uživatele do týmu (player|coach|parent|staff)')]
final class TeamMemberCommand extends Command
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
            ->addArgument('team', InputArgument::REQUIRED, 'Slug týmu')
            ->addArgument('membership', InputArgument::OPTIONAL, 'player|coach|parent|staff', 'player');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = $this->users->findOneByEmail((string) $input->getArgument('email'));
        $team = $this->em->getRepository(TeamCategory::class)->findOneBy(['slug' => $input->getArgument('team')]);
        $membership = Membership::tryFrom((string) $input->getArgument('membership'));

        if (null === $user || null === $team || null === $membership) {
            $io->error('Neznámý uživatel, tým nebo typ členství.');

            return Command::FAILURE;
        }

        $user->joinTeam($team, $membership);
        $this->em->flush();
        $io->success(sprintf('%s je v týmu %s jako %s.', $user->getEmail(), $team->getSlug(), $membership->value));

        return Command::SUCCESS;
    }
}
