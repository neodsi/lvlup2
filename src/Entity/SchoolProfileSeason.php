<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RegistrationStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'school_profile_seasons')]
#[ORM\UniqueConstraint(name: 'uq_school_profile_season', columns: ['school_profile_id', 'season_id'])]
#[ORM\HasLifecycleCallbacks]
class SchoolProfileSeason
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $schoolProfileId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $seasonId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $schoolId;

    #[ORM\Column(type: 'string', length: 50, enumType: RegistrationStatus::class, options: ['default' => 'not_registered'])]
    private RegistrationStatus $registrationStatus = RegistrationStatus::NotRegistered;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $activityIds = null;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $ageGroupId = null;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $levelId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $emergencyContact = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $injuryWarning = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $accepted = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

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

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): static
    {
        $this->seasonId = $seasonId;

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

    public function getRegistrationStatus(): RegistrationStatus
    {
        return $this->registrationStatus;
    }

    public function setRegistrationStatus(RegistrationStatus $registrationStatus): static
    {
        $this->registrationStatus = $registrationStatus;

        return $this;
    }

    public function getActivityIds(): ?array
    {
        return $this->activityIds;
    }

    public function setActivityIds(?array $activityIds): static
    {
        $this->activityIds = $activityIds;

        return $this;
    }

    public function getAgeGroupId(): ?string
    {
        return $this->ageGroupId;
    }

    public function setAgeGroupId(?string $ageGroupId): static
    {
        $this->ageGroupId = $ageGroupId;

        return $this;
    }

    public function getLevelId(): ?string
    {
        return $this->levelId;
    }

    public function setLevelId(?string $levelId): static
    {
        $this->levelId = $levelId;

        return $this;
    }

    public function getEmergencyContact(): ?array
    {
        return $this->emergencyContact;
    }

    public function setEmergencyContact(?array $emergencyContact): static
    {
        $this->emergencyContact = $emergencyContact;

        return $this;
    }

    public function getInjuryWarning(): ?string
    {
        return $this->injuryWarning;
    }

    public function setInjuryWarning(?string $injuryWarning): static
    {
        $this->injuryWarning = $injuryWarning;

        return $this;
    }

    public function getAccepted(): ?array { return $this->accepted; }
    public function setAccepted(?array $accepted): static { $this->accepted = $accepted; return $this; }

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
}
