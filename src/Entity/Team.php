<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeePaidBy;
use App\Enum\StripeAccountStatus;
use App\Enum\TeamStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'teams')]
class Team
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(type: 'string', enumType: TeamStatus::class, options: ['default' => 'waiting'])]
    private TeamStatus $status = TeamStatus::Waiting;

    #[ORM\Column(type: 'string', length: 3, options: ['default' => 'EUR'])]
    private string $currency = 'EUR';

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $currentSeasonId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $currentSlug = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $previousSlugs = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $avatarPath = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $carouselPaths = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $invoicePrefix = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $invoiceNumberingStart = 1;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $invoiceAddress = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $stripeAccountId = null;

    #[ORM\Column(type: 'string', enumType: StripeAccountStatus::class, options: ['default' => 'not_created'])]
    private StripeAccountStatus $stripeAccountStatus = StripeAccountStatus::NotCreated;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $stripePaymentCapabilities = null;

    #[ORM\Column(type: 'string', enumType: FeePaidBy::class, options: ['default' => 'student'])]
    private FeePaidBy $feePaidBy = FeePaidBy::Student;

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

    public function getId(): string
    {
        return $this->id;
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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): TeamStatus
    {
        return $this->status;
    }

    public function setStatus(TeamStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getCurrentSeasonId(): ?string
    {
        return $this->currentSeasonId;
    }

    public function setCurrentSeasonId(?string $currentSeasonId): static
    {
        $this->currentSeasonId = $currentSeasonId;

        return $this;
    }

    public function getCurrentSlug(): ?string
    {
        return $this->currentSlug;
    }

    public function setCurrentSlug(?string $currentSlug): static
    {
        $this->currentSlug = $currentSlug;

        return $this;
    }

    public function getPreviousSlugs(): ?array
    {
        return $this->previousSlugs;
    }

    public function setPreviousSlugs(?array $previousSlugs): static
    {
        $this->previousSlugs = $previousSlugs;

        return $this;
    }

    public function getAvatarPath(): ?string
    {
        return $this->avatarPath;
    }

    public function setAvatarPath(?string $avatarPath): static
    {
        $this->avatarPath = $avatarPath;

        return $this;
    }

    public function getCarouselPaths(): ?array
    {
        return $this->carouselPaths;
    }

    public function setCarouselPaths(?array $carouselPaths): static
    {
        $this->carouselPaths = $carouselPaths;

        return $this;
    }

    public function getInvoicePrefix(): ?string
    {
        return $this->invoicePrefix;
    }

    public function setInvoicePrefix(?string $invoicePrefix): static
    {
        $this->invoicePrefix = $invoicePrefix;

        return $this;
    }

    public function getInvoiceNumberingStart(): int
    {
        return $this->invoiceNumberingStart;
    }

    public function setInvoiceNumberingStart(int $invoiceNumberingStart): static
    {
        $this->invoiceNumberingStart = $invoiceNumberingStart;

        return $this;
    }

    public function getInvoiceAddress(): ?string
    {
        return $this->invoiceAddress;
    }

    public function setInvoiceAddress(?string $invoiceAddress): static
    {
        $this->invoiceAddress = $invoiceAddress;

        return $this;
    }

    public function getStripeAccountId(): ?string
    {
        return $this->stripeAccountId;
    }

    public function setStripeAccountId(?string $stripeAccountId): static
    {
        $this->stripeAccountId = $stripeAccountId;

        return $this;
    }

    public function getStripeAccountStatus(): StripeAccountStatus
    {
        return $this->stripeAccountStatus;
    }

    public function setStripeAccountStatus(StripeAccountStatus $stripeAccountStatus): static
    {
        $this->stripeAccountStatus = $stripeAccountStatus;

        return $this;
    }

    public function getStripePaymentCapabilities(): ?array
    {
        return $this->stripePaymentCapabilities;
    }

    public function setStripePaymentCapabilities(?array $stripePaymentCapabilities): static
    {
        $this->stripePaymentCapabilities = $stripePaymentCapabilities;

        return $this;
    }

    public function getFeePaidBy(): FeePaidBy
    {
        return $this->feePaidBy;
    }

    public function setFeePaidBy(FeePaidBy $feePaidBy): static
    {
        $this->feePaidBy = $feePaidBy;

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
