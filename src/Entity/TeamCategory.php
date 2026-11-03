<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'team_categories')]
class TeamCategory
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** Např. a-tym, u19, u11 – objevuje se v tokenech, neměnit. */
    #[ORM\Column(length: 50, unique: true)]
    private string $slug;

    #[ORM\Column(length: 100)]
    private string $name;

    public function __construct(string $slug, string $name)
    {
        $this->slug = $slug;
        $this->name = $name;
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
}
