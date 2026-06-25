<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ScheduleType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'payment_schedule_templates')]
#[ORM\HasLifecycleCallbacks]
class PaymentScheduleTemplate
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

    #[ORM\Column(type: 'string', length: 50, enumType: ScheduleType::class)]
    private ScheduleType $type;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $nbPayments = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $intervalDuration = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $dayOfMonth = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fixedDates = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $fixedFirstDateIsAtAttribution = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $minAmount = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'public'])]
    private string $visibility = 'public';

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $priceModifierId = null;

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

    public function getType(): ScheduleType
    {
        return $this->type;
    }

    public function setType(ScheduleType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getNbPayments(): ?int
    {
        return $this->nbPayments;
    }

    public function setNbPayments(?int $nbPayments): static
    {
        $this->nbPayments = $nbPayments;

        return $this;
    }

    public function getIntervalDuration(): ?int
    {
        return $this->intervalDuration;
    }

    public function setIntervalDuration(?int $intervalDuration): static
    {
        $this->intervalDuration = $intervalDuration;

        return $this;
    }

    public function getDayOfMonth(): ?int
    {
        return $this->dayOfMonth;
    }

    public function setDayOfMonth(?int $dayOfMonth): static
    {
        $this->dayOfMonth = $dayOfMonth;

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getFixedDates(): ?array
    {
        return $this->fixedDates;
    }

    public function setFixedDates(?array $fixedDates): static
    {
        $this->fixedDates = $fixedDates;

        return $this;
    }

    public function isFixedFirstDateIsAtAttribution(): bool
    {
        return $this->fixedFirstDateIsAtAttribution;
    }

    public function setFixedFirstDateIsAtAttribution(bool $fixedFirstDateIsAtAttribution): static
    {
        $this->fixedFirstDateIsAtAttribution = $fixedFirstDateIsAtAttribution;

        return $this;
    }

    public function getMinAmount(): ?int
    {
        return $this->minAmount;
    }

    public function setMinAmount(?int $minAmount): static
    {
        $this->minAmount = $minAmount;

        return $this;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setVisibility(string $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getPriceModifierId(): ?string
    {
        return $this->priceModifierId;
    }

    public function setPriceModifierId(?string $priceModifierId): static
    {
        $this->priceModifierId = $priceModifierId;

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
