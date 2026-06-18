<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SchoolProfileStatus;
use App\Enum\SchoolRole;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: \App\Repository\SchoolProfileRepository::class)]
#[ORM\Table(name: 'school_profiles')]
#[ORM\UniqueConstraint(name: 'uq_school_profile', columns: ['school_id', 'profile_id'])]
#[ORM\HasLifecycleCallbacks]
class SchoolProfile
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: School::class)]
    #[ORM\JoinColumn(name: 'school_id', referencedColumnName: 'id', nullable: false)]
    private ?School $school = null;

    #[ORM\ManyToOne(targetEntity: Profile::class, inversedBy: 'schoolProfiles')]
    #[ORM\JoinColumn(name: 'profile_id', referencedColumnName: 'id', nullable: true)]
    private ?Profile $profile = null;

    #[ORM\Column(type: 'string', enumType: SchoolRole::class, length: 50)]
    private SchoolRole $role;

    #[ORM\Column(type: 'string', enumType: SchoolProfileStatus::class, length: 50, options: ['default' => 'waiting'])]
    private SchoolProfileStatus $status = SchoolProfileStatus::Waiting;

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

    public function getSchool(): ?School
    {
        return $this->school;
    }

    public function setSchool(?School $school): static
    {
        $this->school = $school;

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

    public function getRole(): SchoolRole
    {
        return $this->role;
    }

    public function setRole(SchoolRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getStatus(): SchoolProfileStatus
    {
        return $this->status;
    }

    public function setStatus(SchoolProfileStatus $status): static
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
