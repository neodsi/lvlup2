<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExpirationType;
use App\Enum\PackageType;
use App\Enum\ValidityStartType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'packages')]
#[ORM\HasLifecycleCallbacks]
class Package
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $schoolId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $seasonId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', enumType: PackageType::class, length: 50)]
    private PackageType $type;

    #[ORM\Column(type: 'integer')]
    private int $price;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $classesQty = null;

    #[ORM\Column(type: 'string', enumType: ValidityStartType::class, length: 50, options: ['default' => 'at_attribution'])]
    private ValidityStartType $validityStartType = ValidityStartType::AtAttribution;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $validityStartsAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'string', enumType: ExpirationType::class, length: 50, options: ['default' => 'seasonal'])]
    private ExpirationType $expirationType = ExpirationType::Seasonal;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $preRegistrationPaymentType = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $usageCount = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $validityDurationDays = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $cancellationDelayMinutes = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $ageMin = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $ageMax = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $applyValidityToExisting = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->id = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): PackageType
    {
        return $this->type;
    }

    public function setType(PackageType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function setPrice(int $price): static
    {
        $this->price = $price;

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

    public function getValidityStartType(): ValidityStartType
    {
        return $this->validityStartType;
    }

    public function setValidityStartType(ValidityStartType $validityStartType): static
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

    public function getExpirationType(): ExpirationType
    {
        return $this->expirationType;
    }

    public function setExpirationType(ExpirationType $expirationType): static
    {
        $this->expirationType = $expirationType;

        return $this;
    }

    public function getPreRegistrationPaymentType(): ?string
    {
        return $this->preRegistrationPaymentType;
    }

    public function setPreRegistrationPaymentType(?string $preRegistrationPaymentType): static
    {
        $this->preRegistrationPaymentType = $preRegistrationPaymentType;

        return $this;
    }

    public function getUsageCount(): int
    {
        return $this->usageCount;
    }

    public function setUsageCount(int $usageCount): static
    {
        $this->usageCount = $usageCount;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getValidityDurationDays(): ?int
    {
        return $this->validityDurationDays;
    }

    public function setValidityDurationDays(?int $validityDurationDays): static
    {
        $this->validityDurationDays = $validityDurationDays;

        return $this;
    }

    public function getCancellationDelayMinutes(): ?int
    {
        return $this->cancellationDelayMinutes;
    }

    public function setCancellationDelayMinutes(?int $cancellationDelayMinutes): static
    {
        $this->cancellationDelayMinutes = $cancellationDelayMinutes;

        return $this;
    }

    public function getAgeMin(): ?int
    {
        return $this->ageMin;
    }

    public function setAgeMin(?int $ageMin): static
    {
        $this->ageMin = $ageMin;

        return $this;
    }

    public function getAgeMax(): ?int
    {
        return $this->ageMax;
    }

    public function setAgeMax(?int $ageMax): static
    {
        $this->ageMax = $ageMax;

        return $this;
    }

    public function isApplyValidityToExisting(): bool
    {
        return $this->applyValidityToExisting;
    }

    public function setApplyValidityToExisting(bool $applyValidityToExisting): static
    {
        $this->applyValidityToExisting = $applyValidityToExisting;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
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
