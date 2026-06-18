<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Gender;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'profiles')]
class Profile
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'profiles')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $firstName;

    #[ORM\Column(type: 'string', length: 100)]
    private string $lastName;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dob = null;

    #[ORM\Column(type: 'string', enumType: Gender::class, nullable: true)]
    private ?Gender $gender = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $addressText = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $avatarPath = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isPrimary = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\OneToMany(mappedBy: 'profile', targetEntity: SchoolProfile::class)]
    private Collection $schoolProfiles;

    public function __construct()
    {
        $this->id           = Uuid::v4()->toRfc4122();
        $this->createdAt    = new \DateTimeImmutable();
        $this->updatedAt    = new \DateTimeImmutable();
        $this->schoolProfiles = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): ?string
    {
        return $this->user?->getId();
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getDob(): ?\DateTimeImmutable
    {
        return $this->dob;
    }

    public function setDob(?\DateTimeImmutable $dob): static
    {
        $this->dob = $dob;

        return $this;
    }

    public function getGender(): ?Gender
    {
        return $this->gender;
    }

    public function setGender(?Gender $gender): static
    {
        $this->gender = $gender;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getAddressText(): ?string
    {
        return $this->addressText;
    }

    public function setAddressText(?string $addressText): static
    {
        $this->addressText = $addressText;

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

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(bool $isPrimary): static
    {
        $this->isPrimary = $isPrimary;

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

    /**
     * @return Collection<int, SchoolProfile>
     */
    public function getSchoolProfiles(): Collection
    {
        return $this->schoolProfiles;
    }

    public function addSchoolProfile(SchoolProfile $schoolProfile): static
    {
        if (!$this->schoolProfiles->contains($schoolProfile)) {
            $this->schoolProfiles->add($schoolProfile);
            $schoolProfile->setProfile($this);
        }

        return $this;
    }

    public function removeSchoolProfile(SchoolProfile $schoolProfile): static
    {
        if ($this->schoolProfiles->removeElement($schoolProfile)) {
            if ($schoolProfile->getProfile() === $this) {
                $schoolProfile->setProfile(null);
            }
        }

        return $this;
    }
}
