<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\IntentOrder;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Package;
use App\Entity\Profile;
use App\Entity\School;
use App\Entity\SchoolProfilePackage;
use App\Entity\SchoolProfileSeason;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\IntentStatus;
use App\Enum\OrderItemType;
use App\Enum\OrderStatus;
use App\Enum\PackageStatus;
use App\Enum\PackageType;
use App\Enum\PaymentMethod;
use App\Enum\SchoolRole;
use App\Service\Email\EmailService;
use App\Service\Payment\PaymentScheduleService;
use App\Service\Payment\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class OrderService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaymentScheduleService $paymentScheduleService,
        private readonly StripeService $stripeService,
        private readonly EmailService $emailService,
    ) {
    }

    // -------------------------------------------------------------------------
    // createOrder
    // -------------------------------------------------------------------------

    /**
     * @throws AccessDeniedException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @return array{intentOrderId: string, stripeUrl: string}|array{orderId: string}
     */
    public function createOrder(array $data, User $currentUser): array
    {
        $schoolId  = $data['schoolId']  ?? throw new \InvalidArgumentException('schoolId is required.');
        $profileId = $data['profileId'] ?? throw new \InvalidArgumentException('profileId is required.');
        $seasonId  = $data['seasonId']  ?? throw new \InvalidArgumentException('seasonId is required.');

        $currentUserProfile = $currentUser->getProfile();

        // Verify the currentUser is a member of the school
        $currentUserSps = $this->resolveSchoolMembership($currentUserProfile?->getId(), $schoolId);
        $school = $this->em->getRepository(School::class)->find($schoolId)
                  ?? throw new \InvalidArgumentException(sprintf('School "%s" not found.', $schoolId));

        $isOwner = $currentUserProfile !== null && $school->getOwnerProfileId() === $currentUserProfile->getId();

        if ($currentUserSps === null && !$isOwner) {
            throw new AccessDeniedException('You are not a member of this school.');
        }

        $isSelf = $currentUserProfile !== null && $currentUserProfile->getId() === $profileId;

        if (!$isSelf) {
            $currentUserRole = $currentUserSps?->getRole() ?? ($isOwner ? SchoolRole::School : null);
            if ($currentUserRole !== SchoolRole::School) {
                throw new AccessDeniedException('You do not have permission to create orders for other members.');
            }
        }

        $season  = $this->em->getRepository(Season::class)->find($seasonId)
                   ?? throw new \InvalidArgumentException(sprintf('Season "%s" not found.', $seasonId));
        $profile = $this->em->getRepository(Profile::class)->find($profileId)
                   ?? throw new \InvalidArgumentException(sprintf('Profile "%s" not found.', $profileId));

        $packageType = $data['packageType'] ?? null;
        if ($packageType === PackageType::SubscriptionOneYear->value) {
            $this->assertNoActiveAnnualSubscription($profileId, $schoolId, $seasonId);
        }

        $paymentMethod = $this->resolvePaymentMethod($data);
        $isOnline      = $this->isOnlinePayment($paymentMethod);

        $scheduleTemplate = null;
        if (!empty($data['paymentScheduleTemplateId'])) {
            $scheduleTemplate = $this->em->getRepository(\App\Entity\PaymentScheduleTemplate::class)
                ->find($data['paymentScheduleTemplateId']);
        }

        $scheduleEntries = [];
        if ($scheduleTemplate !== null) {
            $scheduleEntries = $this->paymentScheduleService->processPaymentDetails($data, $scheduleTemplate);
        }

        if ($isOnline) {
            return $this->handleOnlineOrder($data, $school, $profile, $season, $scheduleEntries, $paymentMethod);
        }

        return $this->handleOnsiteOrder($data, $school, $profile, $season, $scheduleEntries, $scheduleTemplate, $paymentMethod);
    }

    // -------------------------------------------------------------------------
    // updateOrder
    // -------------------------------------------------------------------------

    /**
     * @throws AccessDeniedException
     * @throws \InvalidArgumentException
     */
    public function updateOrder(string $orderId, array $data, User $currentUser): Order
    {
        $order = $this->em->getRepository(Order::class)->find($orderId)
                 ?? throw new \InvalidArgumentException(sprintf('Order "%s" not found.', $orderId));

        $currentUserProfile = $currentUser->getProfile();
        $schoolId           = $order->getSchoolId();

        $currentUserSps = $this->resolveSchoolMembership($currentUserProfile?->getId(), $schoolId);
        $school         = $this->em->getRepository(School::class)->find($schoolId);
        $isOwner        = $currentUserProfile !== null && $school?->getOwnerProfileId() === $currentUserProfile->getId();

        if ($currentUserSps === null && !$isOwner) {
            throw new AccessDeniedException('You are not a member of this school.');
        }

        $currentUserRole = $currentUserSps?->getRole() ?? ($isOwner ? SchoolRole::School : null);
        if ($currentUserRole !== SchoolRole::School) {
            throw new AccessDeniedException('You do not have the orders:update permission.');
        }

        $this->em->wrapInTransaction(function () use ($order, $data): void {
            if (isset($data['totalAmount'])) {
                $order->setTotalAmount((int) $data['totalAmount']);
            }
            if (isset($data['status'])) {
                $order->setStatus(OrderStatus::from($data['status']));
            }
            if (isset($data['packageType'])) {
                $order->setPackageType($data['packageType']);
            }

            $this->em->persist($order);

            if (!empty($data['items'])) {
                $existingItems = $this->em->getRepository(OrderItem::class)->findBy([
                    'orderId' => $order->getId(),
                ]);

                foreach ($existingItems as $item) {
                    $item->setDeletedAt(new \DateTimeImmutable());
                    $this->em->persist($item);
                }

                foreach ($data['items'] as $itemData) {
                    $this->createOrderItem($order, $itemData);
                }
            }
        });

        return $order;
    }

    // -------------------------------------------------------------------------
    // fulfillFromIntent
    // -------------------------------------------------------------------------

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function fulfillFromIntent(string $intentOrderId, string $stripeSessionId): Order
    {
        $intentOrder = $this->em->getRepository(IntentOrder::class)->find($intentOrderId)
                       ?? throw new \InvalidArgumentException(sprintf('IntentOrder "%s" not found.', $intentOrderId));

        if ($intentOrder->getStatus() !== IntentStatus::Pending) {
            throw new \RuntimeException(sprintf(
                'IntentOrder "%s" is not in pending status (current: %s).',
                $intentOrderId,
                $intentOrder->getStatus()->value,
            ));
        }

        $order = null;

        $this->em->wrapInTransaction(function () use ($intentOrder, $stripeSessionId, &$order): void {
            $payload = $intentOrder->getPayload();

            $profile = $this->em->getRepository(Profile::class)->find($payload['profileId'])
                       ?? throw new \InvalidArgumentException('Profile not found in intent payload.');

            $order = $this->buildOrder($payload);
            $this->em->persist($order);
            $this->em->flush();

            $this->createOrderItemsFromPayload($order, $payload);

            $scheduleTemplate = null;
            if (!empty($payload['paymentScheduleTemplateId'])) {
                $scheduleTemplate = $this->em->getRepository(\App\Entity\PaymentScheduleTemplate::class)
                    ->find($payload['paymentScheduleTemplateId']);
            }

            $scheduleEntries = [];
            if ($scheduleTemplate !== null) {
                $scheduleEntries = $this->paymentScheduleService->processPaymentDetails($payload, $scheduleTemplate);
            }

            $paymentMethod = $this->resolvePaymentMethod($payload);
            $this->paymentScheduleService->createSchedules($order, $scheduleEntries, $paymentMethod->value);

            $this->createSchoolProfilePackages($order, $payload);

            $intentOrder->setStatus(IntentStatus::Completed);
            $intentOrder->setStripeCheckoutSessionId($stripeSessionId);
            $this->em->persist($intentOrder);
        });

        /** @var Order $order */

        try {
            $profile = $this->em->getRepository(Profile::class)->find($order->getProfileId());
            if ($profile !== null) {
                $this->emailService->sendOrderConfirmation($order, $profile);
            }
        } catch (\Throwable) {
        }

        return $order;
    }

    // -------------------------------------------------------------------------
    // Private helpers – online/onsite paths
    // -------------------------------------------------------------------------

    /**
     * @param array<int, array{amount: int, dueAt: \DateTimeImmutable}> $scheduleEntries
     * @return array{intentOrderId: string, stripeUrl: string}
     */
    private function handleOnlineOrder(
        array $data,
        School $school,
        Profile $profile,
        Season $season,
        array $scheduleEntries,
        PaymentMethod $paymentMethod,
    ): array {
        $intentOrder = null;
        $stripeUrl   = null;

        $this->em->wrapInTransaction(function () use (
            $data, $school, $profile, $season, $scheduleEntries, $paymentMethod,
            &$intentOrder, &$stripeUrl,
        ): void {
            $intentOrder = $this->buildIntentOrder($data, $school, $season, $profile);
            $this->em->persist($intentOrder);
            $this->em->flush();

            $isAutoPay = $paymentMethod === PaymentMethod::OnlineStripeSepaDebit
                         || $paymentMethod === PaymentMethod::OnlineStripeCustomerBalance;

            $stripeUrl = $this->stripeService->createCheckoutSession(
                $this->buildOrder($data),
                $school,
                $profile,
                $scheduleEntries,
                $isAutoPay,
            );
        });

        return [
            'intentOrderId' => $intentOrder->getId(),
            'stripeUrl'     => $stripeUrl,
        ];
    }

    /**
     * @param array<int, array{amount: int, dueAt: \DateTimeImmutable}> $scheduleEntries
     * @return array{orderId: string}
     */
    private function handleOnsiteOrder(
        array $data,
        School $school,
        Profile $profile,
        Season $season,
        array $scheduleEntries,
        ?\App\Entity\PaymentScheduleTemplate $scheduleTemplate,
        PaymentMethod $paymentMethod,
    ): array {
        $order = null;

        $this->em->wrapInTransaction(function () use (
            $data, $school, $profile, $season, $scheduleEntries, $scheduleTemplate, $paymentMethod, &$order,
        ): void {
            $order = $this->buildOrder($data);
            $this->em->persist($order);
            $this->em->flush();

            $this->createOrderItemsFromPayload($order, $data);
            $this->paymentScheduleService->createSchedules($order, $scheduleEntries, $paymentMethod->value);
            $this->createSchoolProfilePackages($order, $data);
        });

        return ['orderId' => $order->getId()];
    }

    // -------------------------------------------------------------------------
    // Private helpers – builders
    // -------------------------------------------------------------------------

    private function buildOrder(array $data): Order
    {
        $order = new Order();
        $order->setSchoolId($data['schoolId']);
        $order->setSeasonId($data['seasonId']);
        $order->setProfileId($data['profileId']);
        $order->setTotalAmount((int) ($data['totalAmount'] ?? 0));
        $order->setStatus(OrderStatus::Pending);

        if (!empty($data['packageType'])) {
            $order->setPackageType($data['packageType']);
        }

        return $order;
    }

    private function buildIntentOrder(array $data, School $school, Season $season, Profile $profile): IntentOrder
    {
        $intentOrder = new IntentOrder();
        $intentOrder->setSchoolId($school->getId());
        $intentOrder->setSeasonId($season->getId());
        $intentOrder->setProfileId($profile->getId());
        $intentOrder->setStatus(IntentStatus::Pending);
        $intentOrder->setPayload($data);

        return $intentOrder;
    }

    private function createOrderItemsFromPayload(Order $order, array $data): void
    {
        foreach ($data['items'] ?? [] as $itemData) {
            $this->createOrderItem($order, $itemData);
        }
    }

    private function createOrderItem(Order $order, array $itemData): OrderItem
    {
        $item = new OrderItem();
        $item->setOrderId($order->getId());
        $item->setType(
            $itemData['type'] instanceof OrderItemType
                ? $itemData['type']
                : OrderItemType::from($itemData['type']),
        );
        $item->setAmount((int) ($itemData['amount'] ?? 0));

        if (!empty($itemData['packageId'])) {
            $item->setPackageId($itemData['packageId']);
        }
        if (!empty($itemData['label'])) {
            $item->setLabel($itemData['label']);
        }

        $this->em->persist($item);

        return $item;
    }

    private function createSchoolProfilePackages(Order $order, array $data): void
    {
        foreach ($data['items'] ?? [] as $itemData) {
            $typeValue = $itemData['type'] instanceof OrderItemType
                ? $itemData['type']->value
                : (string) ($itemData['type'] ?? '');

            if ($typeValue !== OrderItemType::Package->value) {
                continue;
            }

            $packageId = $itemData['packageId'] ?? null;
            if ($packageId === null) {
                continue;
            }

            $package = $this->em->getRepository(Package::class)->find($packageId);
            if ($package === null) {
                continue;
            }

            $tpp = new SchoolProfilePackage();
            $tpp->setProfileId($order->getProfileId());
            $tpp->setPackageId($package->getId());
            $tpp->setSchoolId($order->getSchoolId());
            $tpp->setSeasonId($order->getSeasonId());
            $tpp->setOrderId($order->getId());
            $tpp->setType($package->getType()->value);
            $tpp->setStatus(PackageStatus::Pending);

            if ($package->getClassesQty() !== null) {
                $tpp->setClassesQty($package->getClassesQty());
            }

            $tpp->setValidityStartType($package->getValidityStartType()->value);

            if ($package->getValidityStartsAt() !== null) {
                $tpp->setValidityStartsAt($package->getValidityStartsAt());
            }
            if ($package->getExpiresAt() !== null) {
                $tpp->setExpiresAt($package->getExpiresAt());
            }

            $this->em->persist($tpp);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers – validation
    // -------------------------------------------------------------------------

    private function assertNoActiveAnnualSubscription(string $profileId, string $schoolId, string $seasonId): void
    {
        $existing = $this->em->getRepository(SchoolProfilePackage::class)->findOneBy([
            'schoolId'  => $schoolId,
            'seasonId'  => $seasonId,
            'profileId' => $profileId,
            'type'      => PackageType::SubscriptionOneYear->value,
            'status'    => PackageStatus::Active,
            'deletedAt' => null,
        ]);

        if ($existing !== null) {
            throw new \RuntimeException(
                'An active annual subscription already exists for this profile and season.',
            );
        }
    }

    private function resolveSchoolMembership(?string $profileId, string $schoolId): ?SchoolProfileSeason
    {
        if ($profileId === null) {
            return null;
        }

        return $this->em->createQueryBuilder()
            ->select('sps')
            ->from(SchoolProfileSeason::class, 'sps')
            ->where('sps.profileId = :profileId')
            ->andWhere('sps.schoolId = :schoolId')
            ->setMaxResults(1)
            ->setParameter('profileId', $profileId)
            ->setParameter('schoolId', $schoolId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function isOrderForCurrentUser(User $currentUser, string $profileId): bool
    {
        $profile = $this->em->getRepository(Profile::class)->find($profileId);

        if ($profile === null) {
            return false;
        }

        return $profile->getUserId() === $currentUser->getId();
    }

    private function resolvePaymentMethod(array $data): PaymentMethod
    {
        $raw = $data['paymentMethod'] ?? PaymentMethod::OnsiteCash->value;

        return $raw instanceof PaymentMethod ? $raw : PaymentMethod::from($raw);
    }

    private function isOnlinePayment(PaymentMethod $method): bool
    {
        return \in_array($method, [
            PaymentMethod::OnlineStripeCheckout,
            PaymentMethod::OnlineStripeCustomerBalance,
            PaymentMethod::OnlineStripeSepaDebit,
            PaymentMethod::OnlineStripeLink,
        ], true);
    }
}
