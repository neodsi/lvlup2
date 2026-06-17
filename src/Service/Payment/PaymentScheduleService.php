<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Order;
use App\Entity\PaymentSchedule;
use App\Entity\PaymentScheduleTemplate;
use App\Enum\Operation;
use App\Enum\PriceModifierType;
use App\Enum\ScheduleStatus;
use App\Enum\ScheduleType;
use App\Enum\ValueType;
use Doctrine\ORM\EntityManagerInterface;

class PaymentScheduleService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Compute payment schedule entries from order data and a schedule template.
     *
     * price_modifiers in $orderData:
     *   - type=cart      : applied once to the cart total
     *   - type=profile   : applied once per student in the cart
     *   - valueType=percentage : value/10000 * amount  (value stored as percent*100, e.g. 500 = 5%)
     *   - valueType=fixed      : add/subtract value in centimes directly
     *
     * @return array<int, array{amount: int, dueAt: \DateTimeImmutable}>
     */
    public function processPaymentDetails(array $orderData, PaymentScheduleTemplate $template): array
    {
        $baseAmount = (int) ($orderData['totalAmount'] ?? 0);

        // --- Apply price modifiers ----------------------------------------
        $modifiers      = $orderData['priceModifiers'] ?? [];
        $profileCount   = max(1, (int) ($orderData['profileCount'] ?? 1));
        $adjustedAmount = $baseAmount;

        foreach ($modifiers as $mod) {
            $modType    = $mod['type']      ?? null;
            $valueType  = $mod['valueType'] ?? ValueType::Fixed->value;
            $operation  = $mod['operation'] ?? Operation::Subtract->value;
            $value      = (int) ($mod['value'] ?? 0);

            $delta = match ($valueType) {
                ValueType::Percentage->value => (int) round($value / 10000 * $adjustedAmount),
                default                      => $value,
            };

            $cartDelta    = ($modType === PriceModifierType::Cart->value)    ? $delta               : 0;
            $profileDelta = ($modType === PriceModifierType::Profile->value) ? $delta * $profileCount : 0;
            $totalDelta   = $cartDelta + $profileDelta;

            $adjustedAmount = match ($operation) {
                Operation::Add->value      => $adjustedAmount + $totalDelta,
                Operation::Subtract->value => $adjustedAmount - $totalDelta,
                default                    => $adjustedAmount,
            };
        }

        $adjustedAmount = max(0, $adjustedAmount);

        // --- Build due-date list from template -------------------------------
        $dueDates = $this->buildDueDates($template);

        if (empty($dueDates)) {
            // Fallback: single payment due immediately
            return [['amount' => $adjustedAmount, 'dueAt' => new \DateTimeImmutable()]];
        }

        $nbPayments = count($dueDates);
        $entries    = [];
        $distributed = 0;

        foreach ($dueDates as $index => $dueAt) {
            $isLast = ($index === $nbPayments - 1);
            // Distribute evenly, remainder goes to last instalment
            $instalment  = $isLast
                ? ($adjustedAmount - $distributed)
                : (int) floor($adjustedAmount / $nbPayments);
            $distributed += $instalment;

            $entries[] = [
                'amount' => $instalment,
                'dueAt'  => $dueAt,
            ];
        }

        return $entries;
    }

    /**
     * Persist PaymentSchedule entities for each schedule entry.
     *
     * @param array<int, array{amount: int, dueAt: \DateTimeImmutable}> $scheduleEntries
     */
    public function createSchedules(Order $order, array $scheduleEntries, string $paymentMethod): void
    {
        foreach ($scheduleEntries as $entry) {
            $schedule = new PaymentSchedule();
            $schedule->setOrderId($order->getId());
            $schedule->setTeamId($order->getTeamId());
            $schedule->setProfileId($order->getProfileId());
            $schedule->setAmount($entry['amount']);
            $schedule->setDueAt($entry['dueAt']);
            $schedule->setStatus(ScheduleStatus::Pending);

            $this->em->persist($schedule);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return \DateTimeImmutable[]
     */
    private function buildDueDates(PaymentScheduleTemplate $template): array
    {
        return match ($template->getType()) {
            ScheduleType::FixedDates => $this->buildFixedDueDates($template),
            ScheduleType::Recurring  => $this->buildRecurringDueDates($template),
        };
    }

    /**
     * @return \DateTimeImmutable[]
     */
    private function buildFixedDueDates(PaymentScheduleTemplate $template): array
    {
        $fixedDates = $template->getFixedDates() ?? [];

        if (empty($fixedDates)) {
            return [new \DateTimeImmutable()];
        }

        $dates = [];

        foreach ($fixedDates as $entry) {
            $rawDate = is_array($entry) ? ($entry['date'] ?? null) : $entry;

            if ($rawDate === null) {
                continue;
            }

            try {
                $dt = new \DateTimeImmutable($rawDate);
            } catch (\Throwable) {
                continue;
            }

            // If the first date is "at attribution", replace it with today
            if ($template->isFixedFirstDateIsAtAttribution() && empty($dates)) {
                $dt = new \DateTimeImmutable();
            }

            $dates[] = $dt;
        }

        return $dates ?: [new \DateTimeImmutable()];
    }

    /**
     * @return \DateTimeImmutable[]
     */
    private function buildRecurringDueDates(PaymentScheduleTemplate $template): array
    {
        $nbPayments      = $template->getNbPayments() ?? 1;
        $intervalDays    = $template->getIntervalDuration() ?? 30;
        $dayOfMonth      = $template->getDayOfMonth();
        $startsAt        = $template->getStartsAt() ?? new \DateTimeImmutable();

        $dates = [];

        for ($i = 0; $i < $nbPayments; $i++) {
            if ($dayOfMonth !== null) {
                // Snap to specific day-of-month
                $base   = $startsAt->modify(sprintf('+%d months', $i));
                $year   = (int) $base->format('Y');
                $month  = (int) $base->format('n');
                $maxDay = (int) $base->format('t');
                $day    = min($dayOfMonth, $maxDay);
                $dates[] = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
            } else {
                $dates[] = $startsAt->modify(sprintf('+%d days', $i * $intervalDays));
            }
        }

        return $dates;
    }
}
