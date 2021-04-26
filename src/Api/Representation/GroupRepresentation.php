<?php declare(strict_types=1);

namespace Group\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\UserRepresentation;

/**
 * Group representation.
 */
class GroupRepresentation extends AbstractEntityRepresentation
{
    /**
     * Cache for the counts of resources.
     *
     * @var array
     */
    protected $cacheCounts = [];

    public function getControllerName()
    {
        return 'group';
    }

    public function getJsonLdType()
    {
        return 'o-module-group:Group';
    }

    public function getJsonLd()
    {
        return [
            'o:id' => $this->id(),
            'o:name' => $this->name(),
            'o:comment' => $this->comment(),
            'o:users' => $this->urlEntities('user'),
            'o:item_sets' => $this->urlEntities('item-set'),
            'o:items' => $this->urlEntities('item'),
            'o:media' => $this->urlEntities('media'),
        ];
    }

    public function getReference()
    {
        return new GroupReference($this->resource, $this->getAdapter());
    }

    public function name(): string
    {
        return $this->resource->getName();
    }

    public function comment(): ?string
    {
        return $this->resource->getComment();
    }

    /**
     * Get the resources associated with this group.
     *
     * @return AbstractResourceEntityRepresentation[]
     */
    public function resources(): array
    {
        $result = [];
        $adapter = $this->getAdapter('resources');
        // Note: Use a workaround because the reverse doctrine relation cannot
        // be set. See the entity.
        // TODO Fix entities for many to many relations.
        // foreach ($this->resource->getResources() as $entity) {
        foreach ($this->resource->getGroupResources() as $groupResourceEntity) {
            $entity = $groupResourceEntity->getResource();
            $result[$entity->getId()] = $adapter->getRepresentation($entity);
        }
        return $result;
    }

    /**
     * Get the users associated with this group.
     *
     * @return UserRepresentation[]
     */
    public function users(): array
    {
        $result = [];
        $adapter = $this->getAdapter('users');
        // Note: Use a workaround because the reverse doctrine relation cannot
        // be set. See the entity.
        // TODO Fix entities for many to many relations.
        // foreach ($this->resource->getUsers() as $entity) {
        foreach ($this->resource->getGroupUsers() as $groupUserEntity) {
            $entity = $groupUserEntity->getUser();
            $result[$entity->getId()] = $adapter->getRepresentation($entity);
        }
        return $result;
    }

    /**
     * Get this group's specific resource count.
     *
     * @param string $resourceType
     * @return int
     */
    public function count($resourceType = 'resources'): int
    {
        if (!isset($this->cacheCounts[$resourceType])) {
            $response = $this->getServiceLocator()->get('Omeka\ApiManager')
                ->search('groups', [
                    'id' => $this->id(),
                    'resource_type' => $resourceType,
                ]);
            $this->cacheCounts[$resourceType] = $response->getTotalResults();
        }
        return $this->cacheCounts[$resourceType];
    }

    public function adminUrl($action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        return $url(
            'admin/group-name',
            [
                'action' => $action ?: 'show',
                'name' => $this->name(),
            ],
            ['force_canonical' => $canonical]
        );
    }

    /**
     * Return the admin URL to the resource browse page for the group.
     *
     * Similar to url(), but with the type of resources.
     *
     * @param string|null $resourceType May be "resource" (unsupported),
     * "item-set", "item", "media" or "user".
     * @param bool $canonical Whether to return an absolute URL
     * @return string
     */
    public function urlEntities($resourceType = null, $canonical = false): string
    {
        $mapResource = [
            null => 'item',
            'resources' => 'resource',
            'items' => 'item',
            'item_sets' => 'item-set',
            'users' => 'user',
        ];
        if (isset($mapResource[$resourceType])) {
            $resourceType = $mapResource[$resourceType];
        }
        $url = $this->getViewHelper('Url');
        return $url(
            'admin/default',
            ['controller' => $resourceType, 'action' => 'browse'],
            [
                'query' => ['group' => $this->name()],
                'force_canonical' => $canonical,
            ]
        );
    }
}
