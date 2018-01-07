<?php
namespace Group\View\Helper;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Group\Entity\Group;
use Omeka\Entity\Item;
use Omeka\Entity\ItemSet;
use Omeka\Entity\Media;
use Omeka\Entity\Resource;
use Omeka\Entity\User;
use PDO;
use Zend\View\Helper\AbstractHelper;

class GroupCount extends AbstractHelper
{
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Return the count for a list of groups for a specified resource type.
     *
     * The stats are available directly as method of Group, so this helper is
     * mainly used for performance (one query for all stats).
     *
     * @todo Use Doctrine native queries (here: DBAL query builder) or repositories.
     *
     * @param array|string $groups If empty, return an array of all groups. The
     * group may be an entity, a representation, a name or an id (an name cannot
     * be an integer).
     * @param string $resourceName If empty returns the count of each resource
     * (user, item set, item and media), and the total (resources and users).
     * @param bool $usedOnly Returns only the used groups (default: all groups).
     * @param string $orderBy Sort column and direction, for example "group.name"
     * (default), "count asc", "item_sets", "items" or "media".
     * @param bool $keyPair Returns a flat array of names and counts when a
     * resource name is set.
     * @return array Associative array with names as keys.
     */
    public function __invoke(
        $groups = [],
        $resourceName = '',
        $usedOnly = false,
        $orderBy = '',
        $keyPair = false
    ) {
        $qb = $this->connection->createQueryBuilder();

        $select = [];
        $select['name'] = 'groups.name';

        $types = [
            'users' => User::class,
            'resources' => Resource::class,
            'item_sets' => ItemSet::class,
            'items' => Item::class,
            'media' => Media::class,
            'user' => User::class,
            'resource' => Resource::class,
            'item_set' => ItemSet::class,
            'item' => Item::class,
            User::class => User::class,
            ItemSet::class => ItemSet::class,
            Item::class => Item::class,
            Media::class => Media::class,
            Resource::class => Resource::class,
        ];
        $resourceType = isset($types[$resourceName]) ? $types[$resourceName] : '';

        $joinTable = $resourceType === User::class ? 'group_user' : 'group_resource';

        $eqGroupGrouping = $qb->expr()->eq('groups.id', $joinTable . '.group_id');
        $eqResourceGrouping = $qb->expr()->eq('resource.id', $joinTable . '.resource_id');

        // Select all types of resource separately and together.
        if (empty($resourceType)) {
            // The total of users and the full total is done separately below.
            $select['resources'] = 'COUNT(resource.resource_type) AS "resources"';
            $select['item_sets'] = 'SUM(CASE WHEN resource.resource_type = "Omeka\\\\Entity\\\\ItemSet" THEN 1 ELSE 0 END) AS "item_sets"';
            $select['items'] = 'SUM(CASE WHEN resource.resource_type = "Omeka\\\\Entity\\\\Item" THEN 1 ELSE 0 END) AS "items"';
            $select['media'] = 'SUM(CASE WHEN resource.resource_type = "Omeka\\\\Entity\\\\Media" THEN 1 ELSE 0 END) AS "media"';
            if ($usedOnly) {
                $qb
                    ->innerJoin('groups', 'group_resource', 'group_resource', $eqGroupGrouping)
                    ->innerJoin('group_resource', 'resource', 'resource', $eqResourceGrouping);
            } else {
                $qb
                    ->leftJoin('groups', 'group_resource', 'group_resource', $eqGroupGrouping)
                    ->leftJoin('group_resource', 'resource', 'resource', $eqResourceGrouping);
            }
        }

        // Select all users or all resources together.
        elseif (in_array($resourceType, [User::class, Resource::class])) {
            $select['count'] = 'COUNT(' . $joinTable . '.group_id) AS "count"';
            if ($usedOnly) {
                $qb
                    ->innerJoin(
                        'groups',
                        $joinTable,
                        $joinTable,
                        $qb->expr()->andX(
                            $eqGroupGrouping,
                            $qb->expr()->isNotNull($joinTable . '.resource_id')
                        ));
            } else {
                $qb
                    ->leftJoin('groups', $joinTable, $joinTable, $eqGroupGrouping);
            }
        }

        // Select one type of resource.
        else {
            $eqResourceType = $qb->expr()->eq('resource.resource_type', ':resource_type');
            $qb
                ->setParameter('resource_type', $resourceType);
            if ($usedOnly) {
                $select['count'] = 'COUNT(group_resource.group_id) AS "count"';
                $qb
                    ->innerJoin('groups', 'group_resource', 'group_resource', $eqGroupGrouping)
                    ->innerJoin(
                        'group_resource',
                        'resource',
                        'resource',
                        $qb->expr()->andX(
                            $eqResourceGrouping,
                            $eqResourceType
                        ));
            } else {
                $select['count'] = 'COUNT(resource.resource_type) AS "count"';
                $qb
                    ->leftJoin('groups', 'group_resource', 'group_resource', $eqGroupGrouping)
                    ->leftJoin(
                        'group_resource',
                        'resource',
                        'resource',
                        $qb->expr()->andX(
                            $eqResourceGrouping,
                            $eqResourceType
                        ));
            }
        }

        if ($groups) {
            // Get a list of group names from a various list of groups (entity,
            // representation, names).
            $groups = array_unique(array_map(function ($v) {
                return is_object($v) ? ($v instanceof Group ? $v->getName() : $v->name()) : $v;
            }, is_array($groups) || $groups instanceof ArrayCollection ? $groups : [$groups]));

            $isId = preg_match('~^\d+$~', reset($groups));
            if ($isId) {
                $groups = array_map('intval', $groups);
                $qb
                    ->andWhere($qb->expr()->in('groups.id', $groups));
            } else {
                // TODO How to do a "WHERE IN" with doctrine and strings?
                $quotedGroups = array_map([$this->connection, 'quote'], $groups);
                $qb
                    ->andWhere($qb->expr()->in('groups.name', $quotedGroups));
            }
        }

        $orderBy = trim($orderBy);
        if (strpos($orderBy, ' ')) {
            $order = explode(' ', $orderBy);
            $orderBy = $orderBy[0];
            $orderDir = $orderBy[1];
        } else {
            $orderBy = $orderBy ?: 'groups.name';
            $orderDir = 'ASC';
        }

        $qb
            ->select($select)
            ->from('groups', 'groups')
            ->groupBy('groups.id')
            ->orderBy($orderBy, $orderDir);

        $stmt = $this->connection->executeQuery($qb, $qb->getParameters());
        $fetchMode = $keyPair && $resourceType
            ? PDO::FETCH_KEY_PAIR
            : (PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);
        $result = $stmt->fetchAll($fetchMode);

        // Manage the exception (all counts of users and resources).
        if (empty($resourceType)) {
            $resultUsers = $this->__invoke($groups, User::class, $usedOnly, $orderBy, $keyPair);
            foreach ($result as $groupName => &$values) {
                $userCount = $resultUsers[$groupName]['count'];
                $userValues = [];
                $userValues['count'] = $userCount + $values['resources'];
                $userValues['users'] = $userCount;
                $values = $userValues + $values;
            }
        }

        return $result;
    }
}
