<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AttendanceStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'event_occurence_profiles')]
#[ORM\HasLifecycleCallbacks]
class EventOccurenceProfile
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $eventOccurenceId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $teamProfileId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $teamId;

    #[ORM\Column(type: 'string', enumType: AttendanceStatus::class, length: 50, options: ['default' => 'unknown'])]
    private AttendanceStatus $status = AttendanceStatus::Unknown;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

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

    public function getEventOccurenceId(): string
    {
        return $this->eventOccurenceId;
    }

    public function setEventOccurenceId(string $eventOccurenceId): static
    {
        $this->eventOccurenceId = $eventOccurenceId;

        return $this;
    }

    public function getTeamProfileId(): string
    {
        return $this->teamProfileId;
    }

    public function setTeamProfileId(string $teamProfileId): static
    {
        $this->teamProfileId = $teamProfileId;

        return $this;
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

    public function getStatus(): AttendanceStatus
    {
        return $this->status;
    }

    public function setStatus(AttendanceStatus $status): static
    {
        $this->status = $status;

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
}
