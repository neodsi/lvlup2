<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Gender;
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

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $sizeTop = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $sizeBottom = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $sizeShoe = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isPrimary = true;

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
        $this->firstName = self::normalizeFirstName($firstName);

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = mb_strtoupper(trim($lastName));

        return $this;
    }

    /**
     * Capitalizes each part of a compound first name (handles hyphens and spaces).
     * e.g. "jean-pierre", "JEAN PIERRE" → "Jean-Pierre", "Jean Pierre"
     */
    public static function normalizeFirstName(string $name): string
    {
        $name  = trim($name);
        $parts = preg_split('/([- ])/', mb_strtolower($name), -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out   = '';
        foreach ($parts as $part) {
            if ($part === '-' || $part === ' ') {
                $out .= $part;
            } elseif ($part !== '') {
                $out .= mb_strtoupper(mb_substr($part, 0, 1)) . mb_substr($part, 1);
            }
        }

        return $out;
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

    public function getSizeTop(): ?string { return $this->sizeTop; }
    public function setSizeTop(?string $v): static { $this->sizeTop = $v; return $this; }

    public function getSizeBottom(): ?string { return $this->sizeBottom; }
    public function setSizeBottom(?string $v): static { $this->sizeBottom = $v; return $this; }

    public function getSizeShoe(): ?string { return $this->sizeShoe; }
    public function setSizeShoe(?string $v): static { $this->sizeShoe = $v; return $this; }

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

}
