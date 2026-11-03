<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AppRole;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'club:user:create', description: 'Vytvoří uživatele (heslo se zadá skrytě)')]
final class UserCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('name', InputArgument::REQUIRED, 'Celé jméno')
            ->addOption('superadmin', null, InputOption::VALUE_NONE, 'Přidělí globální roli superadmin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error('Neplatný e-mail.');

            return Command::INVALID;
        }
        if (null !== $this->users->findOneByEmail($email)) {
            $io->error('Uživatel s tímto e-mailem už existuje.');

            return Command::FAILURE;
        }

        $password = (string) $io->askHidden('Heslo (min. 10 znaků)', static function (?string $value): string {
            if (null === $value || mb_strlen($value) < 10) {
                throw new \RuntimeException('Heslo musí mít alespoň 10 znaků.');
            }

            return $value;
        });

        $user = new User($email, (string) $input->getArgument('name'));
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->markEmailVerified();

        if ($input->getOption('superadmin')) {
            $role = $this->em->getRepository(AppRole::class)->findOneBy(['clientId' => null, 'slug' => 'superadmin'])
                ?? new AppRole(null, 'superadmin', 'Správce přihlašovacího serveru');
            $this->em->persist($role);
            $user->grant($role);
        }

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Uživatel vytvořen, sub = %s', $user->getUuid()));

        return Command::SUCCESS;
    }
}
