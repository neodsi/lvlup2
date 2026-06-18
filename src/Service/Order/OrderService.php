<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\IntentOrder;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Package;
use App\Entity\Profile;
use App\Entity\Season;
use App\Entity\School;
use App\Entity\SchoolProfile;
use App\Entity\SchoolProfilePackage;
use App\Entity\User;
use App\Enum\IntentStatus;
use App\Enum\OrderItemType;
use App\Enum\OrderStatus;
use App\Enum\PackageStatus;
use App\Enum\PackageType;
use App\Enum\PaymentMethod;
use App\Enum\SchoolRole;
use App\Repository\SchoolProfileRepository;
use App\Service\Email\EmailService;
use App\Service\Payment\PaymentScheduleService;
use App\Service\Payment\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class OrderService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SchoolProfileRepository $schoolProfileRepository,
        private readonly PaymentScheduleService $paymentScheduleService,
        private readonly StripeService $stripeService,
        private readonly EmailService $emailService,
    ) {
    }

    // -------------------------------------------------------------------------
    // createOrder
    // -------------------------------------------------------------------------

    /**
     * Create a new order.
     *
     * Returns either:
     *   ['intentOrderId' => string, 'stripeUrl' => string]  — online Stripe payment
     *   ['orderId' => string]                                — onsite payment
     *
     * @throws AccessDeniedException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function createOrder(array $data, User $currentUser): array
    {
        $schoolId    = $data['schoolId']    ?? throw new \InvalidArgumentException('schoolId is required.');
        $profileId = $data['profileId'] ?? throw new \InvalidArgumentException('profileId is required.');
        $seasonId  = $data['seasonId']  ?? throw new \InvalidArgumentException('seasonId is required.');

        // a. Verify the currentUser is a member of the school
        $currentUserSchoolProfile = $this->schoolProfileRepository->findOneByUserAndSchool($currentUser, $schoolId);

        if ($currentUserSchoolProfile === null) {
            throw new AccessDeniedException('You are not a member of this school.');
        }

        // b. If creating for someone else, require orders:update permission (admin+)
        $isSelf = $this->isOrderForCurrentUser($currentUser, $profileId);

        if (!$isSelf) {
            $hasUpdatePermission = \in_array($currentUserSchoolProfile->getRole(), [
                SchoolRole::Admin,
                SchoolRole::Owner,
            ], true);

            if (!$hasUpdatePermission) {
                throw new AccessDeniedException('You do not have permission to create orders for other members.');
            }
        }

        // Load required entities
        $school    = $this->em->getRepository(School::class)->find($schoolId)
                   ?? throw new \InvalidArgumentException(sprintf('School "%s" not found.', $schoolId));
        $season  = $this->em->getRepository(Season::class)->find($seasonId)
                   ?? throw new \InvalidArgumentException(sprintf('Season "%s" not found.', $seasonId));
        $profile = $this->em->getRepository(Profile::class)->find($profileId)
                   ?? throw new \InvalidArgumentException(sprintf('Profile "%s" not found.', $profileId));

        // c. For subscription_one_year, forbid duplicates
        $packageType = $data['packageType'] ?? null;

        if ($packageType === PackageType::SubscriptionOneYear->value) {
            $this->assertNoActiveAnnualSubscription($profileId, $schoolId, $seasonId);
        }

        // d. Create or find SchoolProfile for the profile
        $schoolProfile = $this->findOrCreateSchoolProfile($school, $profile);

        // Determine payment method
        $paymentMethod = $this->resolvePaymentMethod($data);
        $isOnline      = $this->isOnlinePayment($paymentMethod);

        // Load the payment schedule template if provided
        $scheduleTemplate = null;

        if (!empty($data['paymentScheduleTemplateId'])) {
            $scheduleTemplate = $this->em->getRepository(\App\Entity\PaymentScheduleTemplate::class)
                ->find($data['paymentScheduleTemplateId']);
        }

        // g. Calculate payment schedule
        $scheduleEntries = [];

        if ($scheduleTemplate !== null) {
            $scheduleEntries = $this->paymentScheduleService->processPaymentDetails($data, $scheduleTemplate);
        }

        // h. Online (Stripe) path
        if ($isOnline) {
            return $this->handleOnlineOrder($data, $school, $profile, $schoolProfile, $season, $scheduleEntries, $paymentMethod);
        }

        // i. Onsite path
        return $this->handleOnsiteOrder($data, $school, $profile, $schoolProfile, $season, $scheduleEntries, $scheduleTemplate, $paymentMethod);
    }

    // -------------------------------------------------------------------------
    // updateOrder
    // -------------------------------------------------------------------------

    /**
     * Update an existing order.
     * Requires orders:update permission (admin+).
     *
     * @throws AccessDeniedException
     * @throws \InvalidArgumentException
     */
    public function updateOrder(string $orderId, array $data, User $currentUser): Order
    {
        $order = $this->em->getRepository(Order::class)->find($orderId)
                 ?? throw new \InvalidArgumentException(sprintf('Order "%s" not found.', $orderId));

        // Verify orders:update permission
        $schoolProfile = $this->schoolProfileRepository->findOneByUserAndSchool($currentUser, $order->getSchoolId());

        if ($schoolProfile === null) {
            throw new AccessDeniedException('You are not a member of this school.');
        }

        $hasUpdatePermission = \in_array($schoolProfile->getRole(), [
            SchoolRole::Admin,
            SchoolRole::Owner,
        ], true);

        if (!$hasUpdatePermission) {
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

            // Update order items if provided
            if (!empty($data['items'])) {
                // Soft-delete existing items
                $existingItems = $this->em->getRepository(OrderItem::class)->findBy([
                    'orderId' => $order->getId(),
                ]);

                foreach ($existingItems as $item) {
                    $item->setDeletedAt(new \DateTimeImmutable());
                    $this->em->persist($item);
                }

                // Create new items
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
     * Fulfil an order from a pending IntentOrder after a successful Stripe checkout.
     *
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

            $school    = $this->em->getRepository(School::class)->find($payload['schoolId'])
                       ?? throw new \InvalidArgumentException('School not found in intent payload.');
            $season  = $this->em->getRepository(Season::class)->find($payload['seasonId'])
                       ?? throw new \InvalidArgumentException('Season not found in intent payload.');
            $profile = $this->em->getRepository(Profile::class)->find($payload['profileId'])
                       ?? throw new \InvalidArgumentException('Profile not found in intent payload.');

            // Create or find SchoolProfile
            $schoolProfile = $this->findOrCreateSchoolProfile($school, $profile);

            // Create Order
            $order = $this->buildOrder($payload, $schoolProfile);
            $this->em->persist($order);
            $this->em->flush();

            // Create OrderItems
            $this->createOrderItemsFromPayload($order, $payload);

            // Re-calculate schedule entries from payload
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

            // Create SchoolProfilePackages
            $this->createSchoolProfilePackages($order, $payload, $schoolProfile);

            // Mark intent as completed
            $intentOrder->setStatus(IntentStatus::Completed);
            $intentOrder->setStripeCheckoutSessionId($stripeSessionId);
            $this->em->persist($intentOrder);
        });

        /** @var Order $order */

        // Send confirmation email (outside the transaction – non-critical)
        try {
            $profile = $this->em->getRepository(Profile::class)->find($order->getProfileId());

            if ($profile !== null) {
                $this->emailService->sendOrderConfirmation($order, $profile);
            }
        } catch (\Throwable) {
            // Email failure must not abort the order fulfillment
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
        SchoolProfile $schoolProfile,
        Season $season,
        array $scheduleEntries,
        PaymentMethod $paymentMethod,
    ): array {
        // e. Begin transaction
        $intentOrder = null;
        $stripeUrl   = null;

        $this->em->wrapInTransaction(function () use (
            $data,
            $school,
            $profile,
            $schoolProfile,
            $season,
            $scheduleEntries,
            $paymentMethod,
            &$intentOrder,
            &$stripeUrl,
        ): void {
            // f. Create IntentOrder
            $intentOrder = $this->buildIntentOrder($data, $school, $season, $profile);
            $this->em->persist($intentOrder);
            $this->em->flush();

            $isAutoPay = $paymentMethod === PaymentMethod::OnlineStripeSepaDebit
                         || $paymentMethod === PaymentMethod::OnlineStripeCustomerBalance;

            // h. Create Stripe checkout session
            $stripeUrl = $this->stripeService->createCheckoutSession(
                $this->buildOrderForStripe($data, $schoolProfile),
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
        SchoolProfile $schoolProfile,
        Season $season,
        array $scheduleEntries,
        ?\App\Entity\PaymentScheduleTemplate $scheduleTemplate,
        PaymentMethod $paymentMethod,
    ): array {
        $order = null;

        $this->em->wrapInTransaction(function () use (
            $data,
            $school,
            $profile,
            $schoolProfile,
            $season,
            $scheduleEntries,
            $scheduleTemplate,
            $paymentMethod,
            &$order,
        ): void {
            // i. Create Order
            $order = $this->buildOrder($data, $schoolProfile);
            $this->em->persist($order);
            $this->em->flush();

            // Create OrderItems
            $this->createOrderItemsFromPayload($order, $data);

            // Create PaymentSchedules
            $this->paymentScheduleService->createSchedules($order, $scheduleEntries, $paymentMethod->value);

            // Create SchoolProfilePackages
            $this->createSchoolProfilePackages($order, $data, $schoolProfile);
        });

        return ['orderId' => $order->getId()];
    }

    // -------------------------------------------------------------------------
    // Private helpers – builders
    // -------------------------------------------------------------------------

    private function buildOrder(array $data, SchoolProfile $schoolProfile): Order
    {
        $order = new Order();
        $order->setSchoolId($data['schoolId']);
        $order->setSeasonId($data['seasonId']);
        $order->setProfileId($data['profileId']);
        $order->setSchoolProfileId($schoolProfile->getId());
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

    /**
     * Build a temporary Order object used only to pass to StripeService::createCheckoutSession.
     * It is not persisted.
     */
    private function buildOrderForStripe(array $data, SchoolProfile $schoolProfile): Order
    {
        $order = new Order();
        $order->setSchoolId($data['schoolId']);
        $order->setSeasonId($data['seasonId']);
        $order->setProfileId($data['profileId']);
        $order->setSchoolProfileId($schoolProfile->getId());
        $order->setTotalAmount((int) ($data['totalAmount'] ?? 0));
        $order->setStatus(OrderStatus::Pending);

        return $order;
    }

    private function createOrderItemsFromPayload(Order $order, array $data): void
    {
        $items = $data['items'] ?? [];

        foreach ($items as $itemData) {
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

    private function createSchoolProfilePackages(Order $order, array $data, SchoolProfile $schoolProfile): void
    {
        $items = $data['items'] ?? [];

        foreach ($items as $itemData) {
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
            $tpp->setSchoolProfileId($schoolProfile->getId());
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

    private function findOrCreateSchoolProfile(School $school, Profile $profile): SchoolProfile
    {
        $existing = $this->em->getRepository(SchoolProfile::class)->findOneBy([
            'school'    => $school,
            'profile' => $profile,
        ]);

        if ($existing !== null) {
            return $existing;
        }

        $schoolProfile = new SchoolProfile();
        $schoolProfile->setSchool($school);
        $schoolProfile->setProfile($profile);
        $schoolProfile->setRole(SchoolRole::Student);

        $this->em->persist($schoolProfile);
        $this->em->flush();

        return $schoolProfile;
    }

    // -------------------------------------------------------------------------
    // Private helpers – validation
    // -------------------------------------------------------------------------

    private function assertNoActiveAnnualSubscription(string $profileId, string $schoolId, string $seasonId): void
    {
        $existing = $this->em->getRepository(SchoolProfilePackage::class)->findOneBy([
            'schoolId'   => $schoolId,
            'seasonId' => $seasonId,
            'type'     => PackageType::SubscriptionOneYear->value,
            'status'   => PackageStatus::Active,
        ]);

        // Also check by profile via schoolProfile
        if ($existing === null) {
            $existing = $this->em->createQueryBuilder()
                ->select('tpp')
                ->from(SchoolProfilePackage::class, 'tpp')
                ->join(SchoolProfile::class, 'tp', 'WITH', 'tp.id = tpp.schoolProfileId')
                ->join(Profile::class, 'p', 'WITH', 'p.id = tp.profile')
                ->where('p.id = :profileId')
                ->andWhere('tpp.schoolId = :schoolId')
                ->andWhere('tpp.seasonId = :seasonId')
                ->andWhere('tpp.type = :type')
                ->andWhere('tpp.status = :status')
                ->andWhere('tpp.deletedAt IS NULL')
                ->setParameter('profileId', $profileId)
                ->setParameter('schoolId', $schoolId)
                ->setParameter('seasonId', $seasonId)
                ->setParameter('type', PackageType::SubscriptionOneYear->value)
                ->setParameter('status', PackageStatus::Active->value)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        if ($existing !== null) {
            throw new \RuntimeException(
                'An active annual subscription already exists for this profile and season.',
            );
        }
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
