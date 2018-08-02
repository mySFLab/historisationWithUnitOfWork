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
        // Get entities updated after a flush (inner onFlush listener)
        $updatedEntities = $unitOfWork->getScheduledEntityUpdates();

        // parse all entities updated
        foreach ($updatedEntities as $order) {

            // get only our entity
            if ($order instanceof Order) {
                // get all fields updated in our entoty
                $changeset = $unitOfWork->getEntityChangeSet($order);
                if (!is_array($changeset)) {

                    return null;
                }
                // get only the interest field, status in our case.
                if (array_key_exists('status', $changeset)) {
                    $changes = $changeset['status'];
                    $previousValueForField = array_key_exists(0, $changes) ? $changes[0] : null;
                    $newValueForField = array_key_exists(1, $changes) ? $changes[1] : null;
                    // Check if the old value is different that the new value
                    if ($previousValueForField != $newValueForField) {
                        // Order historic instanciation.
                        $orderHistoricStatus = new OrderHistoricStatus();
                        $orderHistoricStatus->setStatusLabel($order->getStatus()['code']);
                        $orderHistoricStatus->setCreatedAt(new \DateTime());
                        $orderHistoricStatus->setOrderId($order->getId());
                        // persist but don't flush. It will done after by Symf
                        $entityManager->persist($orderHistoricStatus);

                        $metaData = $entityManager->getClassMetadata('ApiBundle\Entity\OrderHistoricStatus');

                        $unitOfWork->computeChangeSet($metaData, $orderHistoricStatus);
                    }
                }
            }
        }
    }
}
