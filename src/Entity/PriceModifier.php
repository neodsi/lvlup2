<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Operation;
use App\Enum\PriceModifierType;
use App\Enum\ValueType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'price_modifiers')]
#[ORM\HasLifecycleCallbacks]
class PriceModifier
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $schoolId;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $seasonId = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'integer')]
    private int $value;

    #[ORM\Column(type: 'string', length: 50, enumType: ValueType::class)]
    private ValueType $valueType;

    #[ORM\Column(type: 'string', length: 50, enumType: Operation::class)]
    private Operation $operation;

    #[ORM\Column(type: 'string', length: 50, enumType: PriceModifierType::class)]
    private PriceModifierType $type;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $terms = null;

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

    public function getSchoolId(): string
    {
        return $this->schoolId;
    }

    public function setSchoolId(string $schoolId): static
    {
        $this->schoolId = $schoolId;

        return $this;
    }

    public function getSeasonId(): ?string
    {
        return $this->seasonId;
    }

    public function setSeasonId(?string $seasonId): static
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

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(int $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getValueType(): ValueType
    {
        return $this->valueType;
    }

    public function setValueType(ValueType $valueType): static
    {
        $this->valueType = $valueType;

        return $this;
    }

    public function getOperation(): Operation
    {
        return $this->operation;
    }

    public function setOperation(Operation $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    public function getType(): PriceModifierType
    {
        return $this->type;
    }

    public function setType(PriceModifierType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getTerms(): ?array
    {
        return $this->terms;
    }

    public function setTerms(?array $terms): static
    {
        $this->terms = $terms;

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
}
