<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PackageStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'school_profile_packages')]
#[ORM\HasLifecycleCallbacks]
class SchoolProfilePackage
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $schoolProfileId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $packageId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $schoolId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $seasonId;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $orderId = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $type;

    #[ORM\Column(type: 'string', length: 50, enumType: PackageStatus::class, options: ['default' => 'pending'])]
    private PackageStatus $status = PackageStatus::Pending;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $classesDone = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $classesQty = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $validityStartType = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $validityStartsAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $validityStatus = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSchoolProfileId(): string
    {
        return $this->schoolProfileId;
    }

    public function setSchoolProfileId(string $schoolProfileId): static
    {
        $this->schoolProfileId = $schoolProfileId;

        return $this;
    }

    public function getPackageId(): string
    {
        return $this->packageId;
    }

    public function setPackageId(string $packageId): static
    {
        $this->packageId = $packageId;

        return $this;
    }

    public function getSchoolId(): string
    {
        return $this->schoolId;
    }

    public function setSchoolId(string $schoolId): static
    {
        $this->schoolId = $schoolId;

        return $this;
    }

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): static
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): static
    {
        $this->orderId = $orderId;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): PackageStatus
    {
        return $this->status;
    }

    public function setStatus(PackageStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getClassesDone(): int
    {
        return $this->classesDone;
    }

    public function setClassesDone(int $classesDone): static
    {
        $this->classesDone = $classesDone;

        return $this;
    }

    public function getClassesQty(): ?int
    {
        return $this->classesQty;
    }

    public function setClassesQty(?int $classesQty): static
    {
        $this->classesQty = $classesQty;

        return $this;
    }

    public function getValidityStartType(): ?string
    {
        return $this->validityStartType;
    }

    public function setValidityStartType(?string $validityStartType): static
    {
        $this->validityStartType = $validityStartType;

        return $this;
    }

    public function getValidityStartsAt(): ?\DateTimeImmutable
    {
        return $this->validityStartsAt;
    }

    public function setValidityStartsAt(?\DateTimeImmutable $validityStartsAt): static
    {
        $this->validityStartsAt = $validityStartsAt;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getValidityStatus(): ?string
    {
        return $this->validityStatus;
    }

    public function setValidityStatus(?string $validityStatus): static
    {
        $this->validityStatus = $validityStatus;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }
}
