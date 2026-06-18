<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'event_occurences')]
#[ORM\HasLifecycleCallbacks]
class EventOccurence
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $eventId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $schoolId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurenceAt;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $cancelled = false;

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

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function setEventId(string $eventId): static
    {
        $this->eventId = $eventId;

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

    public function getOccurenceAt(): \DateTimeImmutable
    {
        return $this->occurenceAt;
    }

    public function setOccurenceAt(\DateTimeImmutable $occurenceAt): static
    {
        $this->occurenceAt = $occurenceAt;

        return $this;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function setCancelled(bool $cancelled): static
    {
        $this->cancelled = $cancelled;

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
