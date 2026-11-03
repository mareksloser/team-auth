<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\TeamCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'club:team:create', description: 'Vytvoří kategorii týmu (např. u11 "Mladší přípravka")')]
final class TeamCreateCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Krátký identifikátor do tokenů, např. u11')
            ->addArgument('name', InputArgument::REQUIRED, 'Zobrazovaný název');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = strtolower((string) $input->getArgument('slug'));

        if (!preg_match('/^[a-z0-9-]{1,50}$/', $slug)) {
            $io->error('Slug smí obsahovat jen a-z, 0-9 a pomlčku.');

            return Command::INVALID;
        }
        if (null !== $this->em->getRepository(TeamCategory::class)->findOneBy(['slug' => $slug])) {
            $io->error('Tým už existuje.');

            return Command::FAILURE;
        }

        $this->em->persist(new TeamCategory($slug, (string) $input->getArgument('name')));
        $this->em->flush();
        $io->success('Tým vytvořen.');

        return Command::SUCCESS;
    }
}
