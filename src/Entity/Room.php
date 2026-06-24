<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rooms')]
#[ORM\HasLifecycleCallbacks]
class Room
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

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxCapacity = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $addressText = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?float $addressLat = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?float $addressLng = null;

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

    public function getMaxCapacity(): ?int { return $this->maxCapacity; }
    public function setMaxCapacity(?int $v): static { $this->maxCapacity = $v; return $this; }

    public function getAddressText(): ?string { return $this->addressText; }
    public function setAddressText(?string $v): static { $this->addressText = $v; return $this; }

    public function getAddressLat(): ?float { return $this->addressLat; }
    public function setAddressLat(?float $v): static { $this->addressLat = $v; return $this; }

    public function getAddressLng(): ?float { return $this->addressLng; }
    public function setAddressLng(?float $v): static { $this->addressLng = $v; return $this; }

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
