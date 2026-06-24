<?php

declare(strict_types=1);

namespace App\Service\Season;

use App\Entity\Activity;
use App\Entity\Event;
use App\Entity\Package;
use App\Entity\PaymentScheduleTemplate;
use App\Entity\PriceModifier;
use App\Entity\Room;
use App\Entity\Season;
use App\Entity\School;
use Doctrine\ORM\EntityManagerInterface;

class SeasonService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function createSeason(School $school, array $data): Season
    {
        $season = new Season();
        $season->setSchoolId($school->getId());
        $season->setName($data['name']);
        $season->setStartAt($data['startAt']);
        $season->setEndAt($data['endAt']);

        if (isset($data['closures'])) {
            $season->setClosures($data['closures']);
        }

        $this->em->wrapInTransaction(function () use ($school, $season): void {
            $this->em->persist($season);

            if ($school->getCurrentSeasonId() === null) {
                $school->setCurrentSeasonId($season->getId());
                $this->em->persist($school);
            }
        });

        return $season;
    }

    public function copySeason(
        Season $sourceSeason,
        School $school,
        string $newName,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
    ): Season {
        $newSeason = new Season();
        $newSeason->setSchoolId($school->getId());
        $newSeason->setName($newName);
        $newSeason->setStartAt($start);
        $newSeason->setEndAt($end);
        $newSeason->setCopy($sourceSeason);

        if ($sourceSeason->getClosures() !== null) {
            $newSeason->setClosures($sourceSeason->getClosures());
        }

        // Map old entity ids to new entities (needed for registration_fee_id resolution)
        /** @var array<string, PriceModifier> $priceModifierMap old id => new entity */
        $priceModifierMap = [];

        $this->em->wrapInTransaction(function () use (
            $sourceSeason,
            $newSeason,
            $school,
            &$priceModifierMap,
        ): void {
            $this->em->persist($newSeason);

            // --- Rooms ---
            foreach ($sourceSeason->getRooms() as $sourceRoom) {
                $room = new Room();
                $room->setSchoolId($school->getId());
                $room->setSeasonId($newSeason->getId());
                $room->setName($sourceRoom->getName());
                $this->em->persist($room);
            }

            // --- Price modifiers ---
            $sourceModifiers = $this->em->getRepository(PriceModifier::class)->findBy([
                'seasonId' => $sourceSeason->getId(),
            ]);
            foreach ($sourceModifiers as $sourceMod) {
                $mod = new PriceModifier();
                $mod->setSchoolId($school->getId());
                $mod->setSeasonId($newSeason->getId());
                $mod->setName($sourceMod->getName());
                $mod->setValue($sourceMod->getValue());
                $mod->setValueType($sourceMod->getValueType());
                $mod->setOperation($sourceMod->getOperation());
                $mod->setType($sourceMod->getType());
                $mod->setTerms($sourceMod->getTerms());
                $this->em->persist($mod);
                $priceModifierMap[$sourceMod->getId()] = $mod;
            }

            // --- Payment schedule templates ---
            $sourceTemplates = $this->em->getRepository(PaymentScheduleTemplate::class)->findBy([
                'seasonId' => $sourceSeason->getId(),
            ]);
            foreach ($sourceTemplates as $sourceTpl) {
                $tpl = new PaymentScheduleTemplate();
                $tpl->setSchoolId($school->getId());
                $tpl->setSeasonId($newSeason->getId());
                $tpl->setName($sourceTpl->getName());
                $tpl->setType($sourceTpl->getType());
                $tpl->setNbPayments($sourceTpl->getNbPayments());
                $tpl->setIntervalDuration($sourceTpl->getIntervalDuration());
                $tpl->setDayOfMonth($sourceTpl->getDayOfMonth());
                $tpl->setStartsAt($sourceTpl->getStartsAt());
                $tpl->setFixedDates($sourceTpl->getFixedDates());
                $tpl->setFixedFirstDateIsAtAttribution($sourceTpl->isFixedFirstDateIsAtAttribution());
                $this->em->persist($tpl);
            }

            // --- Events ---
            foreach ($sourceSeason->getEvents() as $sourceEvent) {
                $event = new Event();
                $event->setSchoolId($school->getId());
                $event->setSeasonId($newSeason->getId());
                $event->setName($sourceEvent->getName());
                $event->setType($sourceEvent->getType());
                $event->setRoomId($sourceEvent->getRoomId());
                $event->setAddressId($sourceEvent->getAddressId());
                $event->setTeacherId($sourceEvent->getTeacherId());
                $event->setRrule($sourceEvent->getRrule());
                $event->setStartAt($sourceEvent->getStartAt());
                $event->setEndAt($sourceEvent->getEndAt());
                $event->setMaxParticipants($sourceEvent->getMaxParticipants());
                $event->setRruleDayOrder($sourceEvent->getRruleDayOrder());
                $this->em->persist($event);
            }

            // --- Packages ---
            foreach ($sourceSeason->getPackages() as $sourcePkg) {
                $pkg = new Package();
                $pkg->setSchoolId($school->getId());
                $pkg->setSeasonId($newSeason->getId());
                $pkg->setName($sourcePkg->getName());
                $pkg->setType($sourcePkg->getType());
                $pkg->setPrice($sourcePkg->getPrice());
                $pkg->setClassesQty($sourcePkg->getClassesQty());
                $pkg->setValidityStartType($sourcePkg->getValidityStartType());
                $pkg->setValidityStartsAt($sourcePkg->getValidityStartsAt());
                $pkg->setExpiresAt($sourcePkg->getExpiresAt());
                $pkg->setExpirationType($sourcePkg->getExpirationType());
                $pkg->setPreRegistrationPaymentType($sourcePkg->getPreRegistrationPaymentType());
                $pkg->setApplyValidityToExisting($sourcePkg->isApplyValidityToExisting());
                $this->em->persist($pkg);
            }

            // --- Activities ---
            foreach ($sourceSeason->getActivities() as $sourceActivity) {
                $activity = new Activity();
                $activity->setSchoolId($school->getId());
                $activity->setSeasonId($newSeason->getId());
                $activity->setName($sourceActivity->getName());
                $this->em->persist($activity);
            }

            // --- Resolve registration_fee_id ---
            $sourceRegFeeId = $sourceSeason->getRegistrationFeeId();
            if ($sourceRegFeeId !== null && isset($priceModifierMap[$sourceRegFeeId])) {
                $newSeason->setRegistrationFeeId($priceModifierMap[$sourceRegFeeId]->getId());
                $this->em->persist($newSeason);
            }
        });

        return $newSeason;
    }

    public function setCurrentSeason(School $school, Season $season): void
    {
        $school->setCurrentSeasonId($season->getId());
        $this->em->persist($school);
        $this->em->flush();
    }
}
