<?php declare(strict_types=1);

namespace Group\Mvc\Controller\Plugin;

use Group\Api\Adapter\GroupAdapter;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Permissions\Acl;

class ListGroups extends AbstractPlugin
{
    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var Acl
     */
    protected $acl;

    public function __construct(ApiManager$api, Acl $acl)
    {
        $this->api = $api;
        $this->acl = $acl;
    }

    /**
     * Helper to return groups of a resource or a user, by group name.
     *
     * Only admin can view groups of users.
     *
     * @param AbstractEntityRepresentation $resource Resource or user
     * @param string $contentType "id", "resource", "reference" or "representation" (default).
     * @return array|\Group\Entity\Group[] The list of groups associated by
     * group name.
     */
    public function __invoke(?AbstractEntityRepresentation $resourceOrUser = null, $contentType = null): array
    {
        // Don't ask for a resource that is not yet fully created.
        if (is_null($resourceOrUser) || empty($resourceOrUser->id())) {
            return [];
        }

        // The groups are not shown to public, but only to users.
        if (!$this->acl->userIsAllowed(GroupAdapter::class, 'search')
            && !$this->acl->userIsAllowed(GroupAdapter::class, 'read')
        ) {
            return [];
        }

        // Resource representation have method resourceName(), but site page and
        // and user don't. Site page has no getControllerName().
        $entityColumnNames = [
            'item-set' => 'item_set_id',
            'item' => 'item_id',
            'media' => 'media_id',
            'user' => 'user_id',
        ];
        if (!isset($entityColumnNames[$resourceOrUser->getControllerName()])) {
            return [];
        }

        if (!in_array($contentType, ['representation', 'resource', 'reference', 'id'])) {
            $contentType = 'representation';
        }

        $columnName = $entityColumnNames[$resourceOrUser->getControllerName()];

        $options = ['responseContent' => $contentType];
        if ($contentType === 'id') {
            $options['returnScalar'] = 'name';
        }
        $list = $this->api
            ->search('groups', [$columnName => $resourceOrUser->id()], $options)
            ->getContent();
        if (!count($list)) {
            return [];
        }

        switch ($contentType) {
            case 'id':
                return array_flip($list);
            case 'resource':
                foreach ($list as $entity) {
                    $result[$entity->getName()] = $entity;
                }
                break;
            case 'reference':
            case 'representation':
            default:
                foreach ($list as $entity) {
                    $result[$entity->name()] = $entity;
                }
                break;
        }
        return  $result;
    }
}
