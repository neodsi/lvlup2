<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
    private string $teamId;

    #[ORM\ManyToOne(targetEntity: Team::class, inversedBy: 'seasons')]
    #[ORM\JoinColumn(name: 'teamId', referencedColumnName: 'id', nullable: false)]
    private Team $team;

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

    /**
     * Array of {start_at, end_at, label} objects.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $closures = null;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $copyId = null;

    #[ORM\ManyToOne(targetEntity: Season::class)]
    #[ORM\JoinColumn(name: 'copyId', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Season $copy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** @var Collection<int, mixed> */
    #[ORM\OneToMany(mappedBy: 'season', targetEntity: Activity::class)]
    private Collection $activities;

    /** @var Collection<int, mixed> */
    #[ORM\OneToMany(mappedBy: 'season', targetEntity: Room::class)]
    private Collection $rooms;

    /** @var Collection<int, mixed> */
    #[ORM\OneToMany(mappedBy: 'season', targetEntity: Level::class)]
    private Collection $levels;

    /** @var Collection<int, mixed> */
    #[ORM\OneToMany(mappedBy: 'season', targetEntity: AgeGroup::class)]
    private Collection $ageGroups;

    /** @var Collection<int, mixed> */
    #[ORM\OneToMany(mappedBy: 'season', targetEntity: Event::class)]
    private Collection $events;

    /** @var Collection<int, mixed> */
    #[ORM\OneToMany(mappedBy: 'season', targetEntity: Package::class)]
    private Collection $packages;

    public function __construct()
    {
        $this->id         = Uuid::v4()->toRfc4122();
        $this->createdAt  = new \DateTimeImmutable();
        $this->updatedAt  = new \DateTimeImmutable();
        $this->activities = new ArrayCollection();
        $this->rooms      = new ArrayCollection();
        $this->levels     = new ArrayCollection();
        $this->ageGroups  = new ArrayCollection();
        $this->events     = new ArrayCollection();
        $this->packages   = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTeamId(): string
    {
        return $this->teamId;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function setTeam(Team $team): static
    {
        $this->team   = $team;
        $this->teamId = $team->getId();

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

    public function getRegistrationFeeId(): ?string
    {
        return $this->registrationFeeId;
    }

    public function setRegistrationFeeId(?string $registrationFeeId): static
    {
        $this->registrationFeeId = $registrationFeeId;

        return $this;
    }

    public function getPlanningImagePath(): ?string
    {
        return $this->planningImagePath;
    }

    public function setPlanningImagePath(?string $planningImagePath): static
    {
        $this->planningImagePath = $planningImagePath;

        return $this;
    }

    public function getPackagesImagePath(): ?string
    {
        return $this->packagesImagePath;
    }

    public function setPackagesImagePath(?string $packagesImagePath): static
    {
        $this->packagesImagePath = $packagesImagePath;

        return $this;
    }

    public function getClosures(): ?array
    {
        return $this->closures;
    }

    public function setClosures(?array $closures): static
    {
        $this->closures = $closures;

        return $this;
    }

    public function getCopyId(): ?string
    {
        return $this->copyId;
    }

    public function getCopy(): ?Season
    {
        return $this->copy;
    }

    public function setCopy(?Season $copy): static
    {
        $this->copy   = $copy;
        $this->copyId = $copy?->getId();

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

    public function getActivities(): Collection
    {
        return $this->activities;
    }

    public function getRooms(): Collection
    {
        return $this->rooms;
    }

    public function getLevels(): Collection
    {
        return $this->levels;
    }

    public function getAgeGroups(): Collection
    {
        return $this->ageGroups;
    }

    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function getPackages(): Collection
    {
        return $this->packages;
    }
}
