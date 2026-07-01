<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SchoolProfileStatus;
use App\Enum\SchoolRole;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'school_profile_seasons')]
#[ORM\UniqueConstraint(name: 'uq_school_profile_season', columns: ['profile_id', 'school_id', 'season_id', 'role'])]
#[ORM\HasLifecycleCallbacks]
class SchoolProfileSeason
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $profileId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $seasonId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $schoolId;

    #[ORM\Column(type: 'string', length: 50, enumType: SchoolRole::class)]
    private SchoolRole $role;

    #[ORM\Column(type: 'string', length: 50, enumType: SchoolProfileStatus::class, options: ['default' => 'waiting'])]
    private SchoolProfileStatus $status = SchoolProfileStatus::Waiting;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $accepted = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    // Non-mapped — hydrated manually by controllers after loading
    private ?Profile $profile = null;

    public function __construct()
    {
        $this->id        = Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }

    public function getProfileId(): string { return $this->profileId; }
    public function setProfileId(string $profileId): static { $this->profileId = $profileId; return $this; }

    public function getSeasonId(): string { return $this->seasonId; }
    public function setSeasonId(string $seasonId): static { $this->seasonId = $seasonId; return $this; }

    public function getSchoolId(): string { return $this->schoolId; }
    public function setSchoolId(string $schoolId): static { $this->schoolId = $schoolId; return $this; }

    public function getRole(): SchoolRole { return $this->role; }
    public function setRole(SchoolRole $role): static { $this->role = $role; return $this; }

    public function getStatus(): SchoolProfileStatus { return $this->status; }
    public function setStatus(SchoolProfileStatus $status): static { $this->status = $status; return $this; }

    public function getStripeCustomerId(): ?string { return $this->stripeCustomerId; }
    public function setStripeCustomerId(?string $stripeCustomerId): static { $this->stripeCustomerId = $stripeCustomerId; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note; return $this; }

    public function getAccepted(): ?array { return $this->accepted; }
    public function setAccepted(?array $accepted): static { $this->accepted = $accepted; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }

    public function getProfile(): ?Profile { return $this->profile; }
    public function setProfile(?Profile $profile): static { $this->profile = $profile; return $this; }

    public function getUser(): ?User { return $this->profile?->getUser(); }
}
