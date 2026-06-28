<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Event;
use App\Entity\EventOccurence;
use App\Entity\Season;
use App\Entity\School;
use App\Enum\EventType;
use Doctrine\ORM\EntityManagerInterface;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;

class EventService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function createEvent(School $school, Season $season, array $data): Event
    {
        $event = new Event();
        $event->setSchoolId($school->getId());
        $event->setSeasonId($season->getId());
        $event->setName($data['name']);
        $event->setType($data['type'] instanceof EventType ? $data['type'] : EventType::from($data['type']));
        $event->setRrule($data['rrule']);
        $event->setStartAt($data['startAt']);
        $event->setEndAt($data['endAt']);

        if (isset($data['roomId'])) {
            $event->setRoomId($data['roomId']);
        }
        if (isset($data['addressId'])) {
            $event->setAddressId($data['addressId']);
        }
        if (isset($data['teacherId'])) {
            $event->setTeacherId($data['teacherId']);
        }
        if (isset($data['maxParticipants'])) {
            $event->setMaxParticipants($data['maxParticipants']);
        }
        if (isset($data['rruleDayOrder'])) {
            $event->setRruleDayOrder($data['rruleDayOrder']);
        }
        if (isset($data['visible'])) {
            $event->setVisible((bool) $data['visible']);
        }
        if (array_key_exists('minAge', $data)) {
            $event->setMinAge($data['minAge']);
        }
        if (array_key_exists('maxAge', $data)) {
            $event->setMaxAge($data['maxAge']);
        }
        if (array_key_exists('description', $data)) {
            $event->setDescription($data['description'] ?: null);
        }

        $this->em->wrapInTransaction(function () use ($event, $season): void {
            $this->em->persist($event);
            $this->em->flush();
            $this->generateOccurrences($event, $season);
        });

        return $event;
    }

    public function updateEvent(Event $event, array $data): Event
    {
        if (isset($data['name'])) {
            $event->setName($data['name']);
        }
        if (isset($data['type'])) {
            $event->setType($data['type'] instanceof EventType ? $data['type'] : EventType::from($data['type']));
        }
        if (isset($data['rrule'])) {
            $event->setRrule($data['rrule']);
        }
        if (isset($data['startAt'])) {
            $event->setStartAt($data['startAt']);
        }
        if (isset($data['endAt'])) {
            $event->setEndAt($data['endAt']);
        }
        if (array_key_exists('roomId', $data)) {
            $event->setRoomId($data['roomId']);
        }
        if (array_key_exists('addressId', $data)) {
            $event->setAddressId($data['addressId']);
        }
        if (array_key_exists('teacherId', $data)) {
            $event->setTeacherId($data['teacherId']);
        }
        if (array_key_exists('maxParticipants', $data)) {
            $event->setMaxParticipants($data['maxParticipants']);
        }
        if (array_key_exists('rruleDayOrder', $data)) {
            $event->setRruleDayOrder($data['rruleDayOrder']);
        }
        if (isset($data['visible'])) {
            $event->setVisible((bool) $data['visible']);
        }
        if (array_key_exists('minAge', $data)) {
            $event->setMinAge($data['minAge']);
        }
        if (array_key_exists('maxAge', $data)) {
            $event->setMaxAge($data['maxAge']);
        }
        if (array_key_exists('description', $data)) {
            $event->setDescription($data['description'] ?: null);
        }

        /** @var Season $season */
        $season = $this->em->getRepository(Season::class)->find($event->getSeasonId());

        $this->em->wrapInTransaction(function () use ($event, $season): void {
            $this->em->persist($event);
            $this->em->flush();
            $this->generateOccurrences($event, $season);
        });

        return $event;
    }

    public function generateOccurrences(Event $event, Season $season): void
    {
        $now = new \DateTimeImmutable();

        // Build closure date ranges for exclusion check
        $closureRanges = [];
        foreach ($season->getClosures() ?? [] as $closure) {
            $closureRanges[] = [
                'start' => new \DateTimeImmutable($closure['start_at']),
                'end'   => new \DateTimeImmutable($closure['end_at']),
            ];
        }

        // Delete only FUTURE non-cancelled occurrences
        $this->em->createQuery(
            'DELETE FROM App\Entity\EventOccurence o
             WHERE o.eventId = :eventId
               AND o.occurenceAt > :now
               AND o.cancelled = false'
        )
            ->setParameter('eventId', $event->getId())
            ->setParameter('now', $now)
            ->execute();

        // Expand rrule using simshaun/recurr
        $startDate = \DateTime::createFromImmutable($event->getStartAt());
        $endDate   = \DateTime::createFromImmutable($season->getEndAt());

        $rule = new Rule($event->getRrule(), $startDate, $endDate);

        $config = new ArrayTransformerConfig();
        $config->enableLastDayOfMonthFix();

        $transformer  = new ArrayTransformer($config);
        $recurrences  = $transformer->transform($rule);

        $this->em->wrapInTransaction(function () use ($event, $season, $recurrences, $closureRanges, $now): void {
            foreach ($recurrences as $recurrence) {
                /** @var \DateTime $recStart */
                $recStart = $recurrence->getStart();
                $occurAt  = \DateTimeImmutable::createFromMutable($recStart);

                // Skip past occurrences
                if ($occurAt <= $now) {
                    continue;
                }

                // Skip dates within closures
                if ($this->isWithinClosure($occurAt, $closureRanges)) {
                    continue;
                }

                $occurrence = new EventOccurence();
                $occurrence->setEventId($event->getId());
                $occurrence->setSchoolId($event->getSchoolId());
                $occurrence->setOccurenceAt($occurAt);
                $this->em->persist($occurrence);
            }
        });
    }

    /**
     * @param array<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $closureRanges
     */
    private function isWithinClosure(\DateTimeImmutable $date, array $closureRanges): bool
    {
        foreach ($closureRanges as $range) {
            if ($date >= $range['start'] && $date <= $range['end']) {
                return true;
            }
        }

        return false;
    }
}
