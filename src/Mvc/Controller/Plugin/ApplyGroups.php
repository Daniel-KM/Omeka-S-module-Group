<?php declare(strict_types=1);

namespace Group\Mvc\Controller\Plugin;

use Doctrine\ORM\EntityManager;
use Group\Entity\GroupResource;
use Group\Entity\GroupUser;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Manager as ApiManager;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Item;
use Omeka\Entity\ItemSet;
use Omeka\Entity\Media;
use Omeka\Entity\User;
use Omeka\Permissions\Acl;

class ApplyGroups extends AbstractPlugin
{
    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var Acl
     */
    protected $acl;

    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @var bool
     */
    protected $isUser;

    /**
     * @param ApiManager $api
     * @param Acl $acl
     * @param EntityManager $entityManager
     */
    public function __construct(ApiManager$api, Acl $acl, EntityManager $entityManager)
    {
        $this->api = $api;
        $this->acl = $acl;
        $this->entityManager = $entityManager;
    }

    /**
     * Apply groups for an entity (user, item set, item or media), with optional
     * recursivity.
     *
     * Recursivity:
     * When assigning an item set, a check is done to all their items to set all
     * their groups according to all their item sets. The same check is done
     * when an item is saved. The groups for media are reset to the same values
     * than the item, if the options are set accordingly.
     *
     * Entities are not flushed.
     *
     * @param Resource|User $entity No action is done with a user.
     * @param array $groups A list of group ids, names or objects (no mix).
     * @param string $collectionAction "replace" (default), "remove" or "append".
     * @param bool $aboveGroups If true, items will take groups from the item
     * sets they belong and medias will take groups from their item.
     * @param bool $recursive If true, the groups of the current entity will be
     * applied below (items for items sets, medias for items).
     */
    public function __invoke(
        AbstractEntity $entity,
        array $groups,
        $collectionAction = 'replace',
        $aboveGroups = false,
        $recursive = false
    ): void {
        $this->isUser = $entity->getResourceId() === User::class;
        $groupEntity = $this->isUser ? GroupUser::class : GroupResource::class;
        switch ($collectionAction) {
            case 'replace':
                if (!$this->acl->userIsAllowed($groupEntity, 'update')) {
                    return;
                }
                break;
            case 'append':
                if (!$this->acl->userIsAllowed($groupEntity, 'create')) {
                    return;
                }
                break;
            case 'remove':
                if (!$this->acl->userIsAllowed($groupEntity, 'delete')) {
                    return;
                }
                break;
        }

        $groups = $this->checkGroups($groups);

        switch ($entity->getResourceId()) {
            case User::class:
                // No groups above and nothing to recursive.
                $this->applyGroupsToEntity($entity, $groups, $collectionAction);
                break;

            case ItemSet::class:
                // No groups above.
                $this->applyGroupsToEntity($entity, $groups, $collectionAction);
                if ($recursive) {
                    if (in_array($collectionAction, ['append', 'remove'])) {
                        $groupEntitiesRepository = $this->entityManager->getRepository(GroupResource::class);
                        $groupEntities = $groupEntitiesRepository->findBy(['resource' => $entity->getId()]);
                        $currentGroups = [];
                        foreach ($groupEntities as $groupEntity) {
                            $group = $groupEntity->getGroup();
                            $currentGroups[$group->getId()] = $group;
                        }
                        // The repository is not up to date, so update directly.
                        $groups = $collectionAction === 'append'
                            ? array_replace($currentGroups, $groups)
                            : array_diff_key($currentGroups, $groups);
                    }
                    foreach ($entity->getItems() as $item) {
                        $this->applyGroupsToItemAndMedia($item, $groups, true, $entity);
                    }
                }
                break;

            case Item::class:
                if ($aboveGroups) {
                    // When groups are item sets ones, the recursivity applies
                    // always on medias too.
                    $this->applyGroupsToItemAndMedia($entity, null, true, null);
                } elseif ($recursive) {
                    $this->applyGroupsToItemAndMedia($entity, $groups, false, null);
                } else {
                    $this->applyGroupsToEntity($entity, $groups, $collectionAction);
                }
                break;

            case Media::class:
                if ($aboveGroups) {
                    // During a creation, the groups will be applied from the
                    // item, if set.
                    if ($entity->getId() && $entity->getItem()->getId()) {
                        $groups = $this->getItemGroups($entity->getItem());
                        $this->applyGroupsToEntity($entity, $groups);
                    }
                } else {
                    $this->applyGroupsToEntity($entity, $groups, $collectionAction);
                }
                // Nothing to recursive.
                break;
        }
    }

    /**
     * Get the list of groups of an item.
     *
     * @param Item $item
     * @return array|null Associative array of groups with id as key.
     */
    protected function getItemGroups(Item $item): ?array
    {
        $itemId = $item->getId();
        if (empty($itemId)) {
            return null;
        }
        $groups = $this->api
            ->search('groups',
                ['item_id' => $itemId],
                ['responseContent' => 'resource']
            )
            ->getContent();
        return $this->listWithIdAsKey($groups);
    }

    /**
     * Get the list of recursive groups for an item.
     *
     * @param Item $item
     * @param ItemSet $itemSet Used when processed recursively.
     * @param array $itemSetGroups
     * @return array Associative array of groups with id as key.
     */
    protected function getItemGroupsFromItemSets(Item $item, ItemSet $itemSet = null, array $itemSetGroups = null): array
    {
        $itemSets = $this->listWithIdAsKey($item->getItemSets());
        if ($itemSet) {
            unset($itemSets[$itemSet->getId()]);
        }
        // This return avoids to set all groups when there are no item set.
        if (empty($itemSets)) {
            return $itemSet && $itemSetGroups ? $itemSetGroups : [];
        }
        $groups = $this->api
            ->search('groups',
                ['item_set_id' => array_keys($itemSets)],
                ['responseContent' => 'resource']
            )
            ->getContent();
        $groups = $this->listWithIdAsKey($groups);
        if ($itemSet && $itemSetGroups) {
            $groups = array_replace($groups, $itemSetGroups);
            ksort($groups);
        }
        return $groups;
    }

    /**
     * Apply groups to an item directly or from its item sets and to its medias.
     *
     * @param Item $item
     * @param array $groups
     * @param bool $aboveGroups
     * @param ItemSet $itemSet
     */
    protected function applyGroupsToItemAndMedia(
        Item $item,
        array $groups = null,
        $aboveGroups = false,
        ItemSet $itemSet = null
    ): void {
        if ($aboveGroups) {
            // Get all groups to apply, with id as key.
            $newGroups = $this->getItemGroupsFromItemSets($item, $itemSet, $groups);
        } else {
            $newGroups = $groups ?: [];
        }

        // Apply these groups to the item.
        $this->applyGroupsToEntity($item, $newGroups);

        // Process all these groups to all the media of the item.
        foreach ($item->getMedia() as $media) {
            $this->applyGroupsToEntity($media, $newGroups);
        }
    }

    /**
     * Apply a list of groups to an entity (add and remove).
     *
     * @param AbstractEntity $entity
     * @param array $groups Associative array of groups with id as key.
     * @param string $collectionAction "replace" (default), "remove" or "append".
     */
    protected function applyGroupsToEntity(AbstractEntity $entity, array $groups, $collectionAction = 'replace'): void
    {
        if ($this->isUser) {
            $groupEntitiesRepository = $this->entityManager->getRepository(GroupUser::class);
            $column = 'user';
        } else {
            $groupEntitiesRepository = $this->entityManager->getRepository(GroupResource::class);
            $column = 'resource';
        }

        //  Get the list of existing groups.
        $currentGroupEntities = $groupEntitiesRepository->findBy([
            $column => $entity->getId(),
        ]);

        switch ($collectionAction) {
            case 'append':
            case 'replace':
                // Get the list of groups that are not already assigned.
                $groupsToAssign = $groups;
                foreach ($currentGroupEntities as $groupEntity) {
                    $group = $groupEntity->getGroup();
                    if (isset($groups[$group->getId()])) {
                        unset($groupsToAssign[$group->getId()]);
                    }
                }

                // Assign each remaining group.
                foreach ($groupsToAssign as $group) {
                    // This check avoids a persist issue.
                    $currentGroupEntity = $groupEntitiesRepository->findBy([
                        'group' => $group->getId(),
                        $column => $entity->getId(),
                    ]);
                    if ($currentGroupEntity) {
                        continue;
                    }

                    $groupEntity = $this->isUser
                        ? new GroupUser($group, $entity)
                        : new GroupResource($group, $entity);
                    $this->entityManager->persist($groupEntity);
                }

                if ($collectionAction === 'append') {
                    break;
                }

                // Unassign the groups that are not to be applied.
                foreach ($currentGroupEntities as $groupEntity) {
                    $group = $groupEntity->getGroup();
                    if (!isset($groups[$group->getId()])) {
                        $this->entityManager->remove($groupEntity);
                    }
                }
                break;

            case 'remove':
                foreach ($currentGroupEntities as $groupEntity) {
                    $group = $groupEntity->getGroup();
                    if (isset($groups[$group->getId()])) {
                        $this->entityManager->remove($groupEntity);
                    }
                }
                break;
        }
    }

    /**
     * Get a list of group objects by id.
     *
     * If groups are names, check if they exist already via database requests to
     * avoid issues between sql and php characters transliterating and casing.
     *
     * @param array $groups List of group ids, names or group objects (entities,
     * representations or references).
     * @return array Associative array of group entities with id as key.
     */
    protected function checkGroups(array $groups): array
    {
        if (empty($groups)) {
            return [];
        }

        $firstGroup = reset($groups);
        if (is_object($firstGroup)) {
            if ($firstGroup instanceof AbstractEntity) {
                return $this->listWithIdAsKey($groups);
            }
            $groups = array_map(function ($v) {
                return $v->id();
            }, $groups);
            $firstGroup = reset($groups);
        } elseif (is_array($firstGroup)) {
            $groups = array_map(function ($v) {
                return $v['o:id'] ?? ($v['o:name'] ?? reset($v));
            }, $groups);
            $firstGroup = reset($groups);
        }

        $isId = preg_match('~^\d+$~', $firstGroup);

        $groups = $this->api
            ->search('groups',
                [$isId ? 'id' : 'name' => $groups],
                ['responseContent' => 'resource']
            )
            ->getContent();
        return $this->listWithIdAsKey($groups);
    }

    /**
     * Helper to list entities with id as key (with implicite deduplication).
     *
     * @param \Doctrine\ORM\PersistentCollection|array $entities
     * @return array Associative array of entities with id as key.
     */
    protected function listWithIdAsKey($entities): array
    {
        // The function array_map() is not available with PersistentCollection.
        $result = [];
        foreach ($entities as $entity) {
            $result[$entity->getId()] = $entity;
        }
        return $result;
    }
}
