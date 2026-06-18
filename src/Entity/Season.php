<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'seasons')]
class Season
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $schoolId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $endAt;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $registrationFeeId = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $planningImagePath = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $packagesImagePath = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $closures = null;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $copyId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->id        = Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }

    public function getSchoolId(): string { return $this->schoolId; }
    public function setSchoolId(string $schoolId): static { $this->schoolId = $schoolId; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getStartAt(): \DateTimeImmutable { return $this->startAt; }
    public function setStartAt(\DateTimeImmutable $v): static { $this->startAt = $v; return $this; }

    public function getEndAt(): \DateTimeImmutable { return $this->endAt; }
    public function setEndAt(\DateTimeImmutable $v): static { $this->endAt = $v; return $this; }

    public function getRegistrationFeeId(): ?string { return $this->registrationFeeId; }
    public function setRegistrationFeeId(?string $v): static { $this->registrationFeeId = $v; return $this; }

    public function getPlanningImagePath(): ?string { return $this->planningImagePath; }
    public function setPlanningImagePath(?string $v): static { $this->planningImagePath = $v; return $this; }

    public function getPackagesImagePath(): ?string { return $this->packagesImagePath; }
    public function setPackagesImagePath(?string $v): static { $this->packagesImagePath = $v; return $this; }

    public function getClosures(): ?array { return $this->closures; }
    public function setClosures(?array $v): static { $this->closures = $v; return $this; }

    public function getCopyId(): ?string { return $this->copyId; }
    public function setCopyId(?string $v): static { $this->copyId = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $v): static { $this->createdAt = $v; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $v): static { $this->updatedAt = $v; return $this; }

    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function setDeletedAt(?\DateTimeImmutable $v): static { $this->deletedAt = $v; return $this; }
}
