<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EventType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'events')]
#[ORM\HasLifecycleCallbacks]
class Event
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $teamId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $seasonId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', enumType: EventType::class, length: 50)]
    private EventType $type;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $roomId = null;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $addressId = null;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $teacherId = null;

    #[ORM\Column(type: 'string', length: 1000)]
    private string $rrule;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $endAt;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxParticipants = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $rruleDayOrder = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToMany(targetEntity: Level::class)]
    #[ORM\JoinTable(
        name: 'event_levels',
        joinColumns: [new ORM\JoinColumn(name: 'event_id', referencedColumnName: 'id')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'level_id', referencedColumnName: 'id')]
    )]
    private Collection $levels;

    #[ORM\ManyToMany(targetEntity: AgeGroup::class)]
    #[ORM\JoinTable(
        name: 'event_age_groups',
        joinColumns: [new ORM\JoinColumn(name: 'event_id', referencedColumnName: 'id')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'age_group_id', referencedColumnName: 'id')]
    )]
    private Collection $ageGroups;

    #[ORM\ManyToMany(targetEntity: Package::class)]
    #[ORM\JoinTable(
        name: 'event_packages',
        joinColumns: [new ORM\JoinColumn(name: 'event_id', referencedColumnName: 'id')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'package_id', referencedColumnName: 'id')]
    )]
    private Collection $packages;

    public function __construct()
    {
        $this->id = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->levels = new ArrayCollection();
        $this->ageGroups = new ArrayCollection();
        $this->packages = new ArrayCollection();
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

    public function getTeamId(): string
    {
        return $this->teamId;
    }

    public function setTeamId(string $teamId): static
    {
        $this->teamId = $teamId;

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

    public function getType(): EventType
    {
        return $this->type;
    }

    public function setType(EventType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getRoomId(): ?string
    {
        return $this->roomId;
    }

    public function setRoomId(?string $roomId): static
    {
        $this->roomId = $roomId;

        return $this;
    }

    public function getAddressId(): ?string
    {
        return $this->addressId;
    }

    public function setAddressId(?string $addressId): static
    {
        $this->addressId = $addressId;

        return $this;
    }

    public function getTeacherId(): ?string
    {
        return $this->teacherId;
    }

    public function setTeacherId(?string $teacherId): static
    {
        $this->teacherId = $teacherId;

        return $this;
    }

    public function getRrule(): string
    {
        return $this->rrule;
    }

    public function setRrule(string $rrule): static
    {
        $this->rrule = $rrule;

        return $this;
    }

    public function getStartAt(): \DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(\DateTimeImmutable $startAt): static
    {
        $this->startAt = $startAt;

        return $this;
    }

    public function getEndAt(): \DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(\DateTimeImmutable $endAt): static
    {
        $this->endAt = $endAt;

        return $this;
    }

    public function getMaxParticipants(): ?int
    {
        return $this->maxParticipants;
    }

    public function setMaxParticipants(?int $maxParticipants): static
    {
        $this->maxParticipants = $maxParticipants;

        return $this;
    }

    public function getRruleDayOrder(): ?int
    {
        return $this->rruleDayOrder;
    }

    public function setRruleDayOrder(?int $rruleDayOrder): static
    {
        $this->rruleDayOrder = $rruleDayOrder;

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

    public function getLevels(): Collection
    {
        return $this->levels;
    }

    public function addLevel(Level $level): static
    {
        if (!$this->levels->contains($level)) {
            $this->levels->add($level);
        }

        return $this;
    }

    public function removeLevel(Level $level): static
    {
        $this->levels->removeElement($level);

        return $this;
    }

    public function getAgeGroups(): Collection
    {
        return $this->ageGroups;
    }

    public function addAgeGroup(AgeGroup $ageGroup): static
    {
        if (!$this->ageGroups->contains($ageGroup)) {
            $this->ageGroups->add($ageGroup);
        }

        return $this;
    }

    public function removeAgeGroup(AgeGroup $ageGroup): static
    {
        $this->ageGroups->removeElement($ageGroup);

        return $this;
    }

    public function getPackages(): Collection
    {
        return $this->packages;
    }

    public function addPackage(Package $package): static
    {
        if (!$this->packages->contains($package)) {
            $this->packages->add($package);
        }

        return $this;
    }

    public function removePackage(Package $package): static
    {
        $this->packages->removeElement($package);

        return $this;
    }
}
