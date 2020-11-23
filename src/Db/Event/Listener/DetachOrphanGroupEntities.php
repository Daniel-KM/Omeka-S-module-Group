<?php declare(strict_types=1);
namespace Group\Db\Event\Listener;

use Doctrine\ORM\Event\PreFlushEventArgs;
use Group\Entity\GroupResource;
use Group\Entity\GroupUser;

/**
 * Automatically detach groups that reference unknown resources or users.
 *
 * It allows to avoid issues during batch creation, when resources are detached.
 */
class DetachOrphanGroupEntities
{
    /**
     * Detach all GroupEntities that reference Entities not currently in the
     * entity manager.
     *
     * @param PreFlushEventArgs $event
     */
    public function preFlush(PreFlushEventArgs $event): void
    {
        $em = $event->getEntityManager();
        $uow = $em->getUnitOfWork();
        $identityMap = $uow->getIdentityMap();

        if (isset($identityMap[GroupResource::class])) {
            foreach ($identityMap[GroupResource::class] as $groupResource) {
                $resource = $groupResource->getResource();
                if ($resource && !$em->contains($resource)) {
                    $em->detach($groupResource);
                }
            }
        }

        if (isset($identityMap[GroupUser::class])) {
            foreach ($identityMap[GroupUser::class] as $groupUser) {
                $user = $groupUser->getUser();
                if ($user && !$em->contains($user)) {
                    $em->detach($groupUser);
                }
            }
        }
    }
}
