<?php

declare(strict_types=1);

namespace App\EventListener;

use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::preRemove)]
class SoftDeleteListener
{
    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!property_exists($entity, 'deletedAt')) {
            return;
        }

        $em = $args->getObjectManager();

        // Set the soft-delete timestamp
        $entity->setDeletedAt(new \DateTimeImmutable());

        // Re-persisting the entity transitions it from STATE_REMOVED back to
        // STATE_MANAGED, which cancels the scheduled DELETE statement.
        $em->persist($entity);
    }
}
