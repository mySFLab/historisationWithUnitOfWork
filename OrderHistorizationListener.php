<?php

namespace ApiBundle\Event;

use ApiBundle\Entity\Order;
use ApiBundle\Entity\OrderHistoricStatus;
use Doctrine\ORM\Event\OnFlushEventArgs;

/**
 * Class OrderHistorisationListener
 */
class OrderHistorisationListener
{
    /**
     * @param OnFlushEventArgs $args
     *
     * @return null
     */
    public function onFlush(OnFlushEventArgs $args) {
        $entityManager = $args->getEntityManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $updatedEntities = $unitOfWork->getScheduledEntityUpdates();

        foreach ($updatedEntities as $order) {

            if ($order instanceof Order) {

                $changeset = $unitOfWork->getEntityChangeSet($order);

                if (!is_array($changeset)) {

                    return null;
                }

                if (array_key_exists('status', $changeset)) {

                    $changes = $changeset['status'];
                    $previousValueForField = array_key_exists(0, $changes) ? $changes[0] : null;
                    $newValueForField = array_key_exists(1, $changes) ? $changes[1] : null;
                    if ($previousValueForField != $newValueForField) {

                        $orderHistoricStatus = new OrderHistoricStatus();
                        $orderHistoricStatus->setStatusLabel($order->getStatus()['code']);
                        $orderHistoricStatus->setCreatedAt(new \DateTime());
                        $orderHistoricStatus->setOrderId($order->getId());

                        $entityManager->persist($orderHistoricStatus);

                        $metaData = $entityManager->getClassMetadata('ApiBundle\Entity\OrderHistoricStatus');

                        $unitOfWork->computeChangeSet($metaData, $orderHistoricStatus);
                    }
                }
            }
        }
    }
}
