<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TeamProfileStatus;
use App\Enum\TeamRole;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: \App\Repository\TeamProfileRepository::class)]
#[ORM\Table(name: 'team_profiles')]
#[ORM\UniqueConstraint(name: 'uq_team_profile', columns: ['team_id', 'profile_id'])]
#[ORM\HasLifecycleCallbacks]
class TeamProfile
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'team_id', referencedColumnName: 'id', nullable: false)]
    private ?Team $team = null;

    #[ORM\ManyToOne(targetEntity: Profile::class, inversedBy: 'teamProfiles')]
    #[ORM\JoinColumn(name: 'profile_id', referencedColumnName: 'id', nullable: true)]
    private ?Profile $profile = null;

    #[ORM\Column(type: 'string', enumType: TeamRole::class, length: 50)]
    private TeamRole $role;

    #[ORM\Column(type: 'string', enumType: TeamProfileStatus::class, length: 50, options: ['default' => 'waiting'])]
    private TeamProfileStatus $status = TeamProfileStatus::Waiting;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

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

    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function setTeam(?Team $team): static
    {
        $this->team = $team;

        return $this;
    }

    public function getProfile(): ?Profile
    {
        return $this->profile;
    }

    public function setProfile(?Profile $profile): static
    {
        $this->profile = $profile;

        return $this;
    }

    public function getRole(): TeamRole
    {
        return $this->role;
    }

    public function setRole(TeamRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getStatus(): TeamProfileStatus
    {
        return $this->status;
    }

    public function setStatus(TeamProfileStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;

        return $this;
    }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note; return $this; }

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
