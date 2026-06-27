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

    #[ORM\Column(type: 'date')]
    private \DateTime $startAt;

    #[ORM\Column(type: 'date')]
    private \DateTime $endAt;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $registrationFeeId = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $registrationPaymentCondition = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTime $preRegistrationsStartAt = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTime $preRegistrationsEndAt = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTime $registrationsStartAt = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTime $registrationsEndAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $registrationPublicDescription = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean')]
    private bool $visible = true;

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

    public function getStartAt(): \DateTimeImmutable { $d = $this->startAt; return $d instanceof \DateTimeImmutable ? $d : \DateTimeImmutable::createFromMutable($d); }
    public function setStartAt(\DateTimeImmutable $v): static { $this->startAt = \DateTime::createFromImmutable($v); return $this; }

    public function getEndAt(): \DateTimeImmutable { $d = $this->endAt; return $d instanceof \DateTimeImmutable ? $d : \DateTimeImmutable::createFromMutable($d); }
    public function setEndAt(\DateTimeImmutable $v): static { $this->endAt = \DateTime::createFromImmutable($v); return $this; }

    public function getRegistrationFeeId(): ?string { return $this->registrationFeeId; }
    public function setRegistrationFeeId(?string $v): static { $this->registrationFeeId = $v; return $this; }

    public function getRegistrationPaymentCondition(): ?string { return $this->registrationPaymentCondition; }
    public function setRegistrationPaymentCondition(?string $v): static { $this->registrationPaymentCondition = $v; return $this; }

    private function toImmutable(?\DateTime $d): ?\DateTimeImmutable { return $d !== null ? ($d instanceof \DateTimeImmutable ? $d : \DateTimeImmutable::createFromMutable($d)) : null; }

    public function getPreRegistrationsStartAt(): ?\DateTimeImmutable { return $this->toImmutable($this->preRegistrationsStartAt); }
    public function setPreRegistrationsStartAt(?\DateTimeImmutable $v): static { $this->preRegistrationsStartAt = $v !== null ? \DateTime::createFromImmutable($v) : null; return $this; }

    public function getPreRegistrationsEndAt(): ?\DateTimeImmutable { return $this->toImmutable($this->preRegistrationsEndAt); }
    public function setPreRegistrationsEndAt(?\DateTimeImmutable $v): static { $this->preRegistrationsEndAt = $v !== null ? \DateTime::createFromImmutable($v) : null; return $this; }

    public function getRegistrationsStartAt(): ?\DateTimeImmutable { return $this->toImmutable($this->registrationsStartAt); }
    public function setRegistrationsStartAt(?\DateTimeImmutable $v): static { $this->registrationsStartAt = $v !== null ? \DateTime::createFromImmutable($v) : null; return $this; }

    public function getRegistrationsEndAt(): ?\DateTimeImmutable { return $this->toImmutable($this->registrationsEndAt); }
    public function setRegistrationsEndAt(?\DateTimeImmutable $v): static { $this->registrationsEndAt = $v !== null ? \DateTime::createFromImmutable($v) : null; return $this; }

    public function getRegistrationPublicDescription(): ?string { return $this->registrationPublicDescription; }
    public function setRegistrationPublicDescription(?string $v): static { $this->registrationPublicDescription = $v; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): static { $this->description = $v; return $this; }

    public function isVisible(): bool { return $this->visible; }
    public function setVisible(bool $v): static { $this->visible = $v; return $this; }

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
