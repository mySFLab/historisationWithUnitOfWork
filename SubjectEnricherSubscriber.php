<?php

/*
 * This file is part of a Wynd project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ApiBundle\EventSubscriber\Serializer;

use ApiBundle\Entity\Order;
use ApiBundle\Entity\OrderHistoricStatus;
use ApiBundle\Repository\OrderHistoricStatusRepository;
use ApiBundle\Service\OrderMetadataCalculatorService;
use Doctrine\ORM\EntityManagerInterface;
use WyndApi\WyndApiCoreBundle\Entity\Order\OrderItem;
use ApiBundle\Service\PickingManager;
use JMS\Serializer\EventDispatcher\Events;
use JMS\Serializer\EventDispatcher\EventSubscriberInterface;
use JMS\Serializer\EventDispatcher\ObjectEvent;
use JMS\Serializer\SerializationContext;
use PickingBundle\Entity\Picking;
use Wynd\LexBundle\Service\LexTools;

/**
 * Class SubjectEnricherSubscriber
 */
class SubjectEnricherSubscriber implements EventSubscriberInterface
{
    /** @var PickingManager */
    private $pickingManager;

    /** @var LexTools */
    private $lexTools;

    /** @var OrderMetadataCalculatorService */
    private $orderMetadataCalculator;

    /**
     * @var OrderHistoricStatusRepository|\Doctrine\Common\Persistence\ObjectRepository
     */
    private $orderHistoricRepository;

    /**
     * @param PickingManager                 $pickingManager
     * @param LexTools                       $lexTools
     * @param OrderMetadataCalculatorService $orderMetadataCalculator
     * @param EntityManagerInterface         $entityManager
     */
    public function __construct(
        PickingManager $pickingManager,
        LexTools $lexTools,
        OrderMetadataCalculatorService $orderMetadataCalculator,
        EntityManagerInterface $entityManager
    ) {
        $this->pickingManager = $pickingManager;
        $this->lexTools = $lexTools;
        $this->orderMetadataCalculator = $orderMetadataCalculator;
        $this->orderHistoricRepository = $entityManager->getRepository(OrderHistoricStatus::class);
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            [
                'event' => Events::POST_SERIALIZE,
                'method' => 'enrichOrderItem',
                'class' => OrderItem::class,
                'format' => 'json',
            ],
            [
                'event' => Events::POST_SERIALIZE,
                'method' => 'enrichOrder',
                'class' => Order::class,
                'format' => 'json',
            ],
            [
                'event' => Events::POST_SERIALIZE,
                'method' => 'enrichOrderHistoric',
                'class' => Order::class,
                'format' => 'json',
            ],
        ];
    }



    /**
     * @param int $orderId
     *
     * @return array
     */
    public function getHistoricByOrder(int $orderId) : array
    {
        $orderHistoric = $this->orderHistoricRepository->findBy(['order' => $orderId]);
        $historicFormatted = $this->transformOrderHistoricToArrays($orderHistoric);

        return $historicFormatted;
    }

    /**
     * We need to transform manually the data due to the following not solved JMS issues :
     *
     * https://github.com/schmittjoh/serializer/issues/319
     * https://github.com/schmittjoh/serializer/pull/341
     *
     * @param array $orderHistorics
     *
     * @return array
     */
    private function transformOrderHistoricToArrays(array $orderHistorics): array
    {
        $orderHistoricFormatted = [];

        /**var OrderHistoricStatus $orderHistoric **/
        foreach ($orderHistorics as $orderHistoric) {
            $orderHistoricFormatted[] = [$orderHistoric->getCreatedAt(), $orderHistoric->getStatusLabel()];
        }

        return $orderHistoricFormatted;
    }

    /**
     * @param ObjectEvent $event
     */
    public function enrichOrderItem(ObjectEvent $event)
    {
        if (!$this->containOneOfThoseGroups($event->getContext(), ['listOrder', 'orders'])) {
            return;
        }

        /** @var OrderItem $order */
        $orderItem = $event->getObject();

        $pickings = $this->pickingManager->getPickingsWithoutSaving($orderItem);

        $event->getVisitor()->setData(
            'picking',
            $this->transformPickingsToArrays($pickings)
        );

        $pickingMetaData = $this->pickingManager->getPickingMetadata($pickings);

        $event->getVisitor()->setData(
            'available_count',
            $pickingMetaData->getAvailableCount()
        );

        $event->getVisitor()->setData(
            'out_of_stock_count',
            $pickingMetaData->getOutOfStockCount()
        );
    }

    /**
     * @param ObjectEvent $event
     */
    public function enrichOrderHistoric(ObjectEvent $event)
    {
        if (!$this->containOneOfThoseGroups($event->getContext(), ['orders'])) {
            return;
        }

        /** @var Order $order */
        $order = $event->getObject();
        $event->getVisitor()->setData(
            'status_historic',
            $this->getHistoricByOrder($order->getId())
        );
    }

    /**
     * @param ObjectEvent $event
     */
    public function enrichOrder(ObjectEvent $event)
    {
        if (!$this->containOneOfThoseGroups($event->getContext(), ['listOrder', 'orders', 'boOrder'])) {
            return;
        }

        /** @var Order $order */
        $order = $event->getObject();

        $event->getVisitor()->setData(
            'bag_count',
            $this->lexTools->getBagCount($order)
        );

        $event->getVisitor()->setData(
            'weight',
            $this->orderMetadataCalculator->getKilogramsWeight($order)
        );
    }

    /**
     * We need to transform manually the data due to the following not solved JMS issues :
     *
     * https://github.com/schmittjoh/serializer/issues/319
     * https://github.com/schmittjoh/serializer/pull/341
     *
     *
     * @param array|Picking[] $pickings
     *
     * @return array
     */
    private function transformPickingsToArrays(array $pickings): array
    {
        $formattedPickings = [];

        foreach ($pickings as $picking) {
            $formattedPickings[] = $picking->getStatusCode();
        }

        return $formattedPickings;
    }

    /**
     * @param SerializationContext $context
     * @param array                $serializationGroups
     *
     * @return bool
     */
    private function containOneOfThoseGroups(SerializationContext $context, array $serializationGroups)
    {
        $groupContext = $context->attributes->get('groups');

        if (!$groupContext->isDefined()) {
            return false;
        }

        $groups = $groupContext->get();

        if (!is_array($groups)) {
            return false;
        }

        foreach ($serializationGroups as $group) {
            if (\in_array($group, $groups, true)) {
                return true;
            }
        }

        return false;
    }
}
