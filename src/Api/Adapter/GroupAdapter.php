<?php
namespace Group\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Group\Api\Representation\GroupRepresentation;
use Group\Entity\Group;
use Group\Entity\GroupResource;
use Group\Entity\GroupUser;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Entity\Item;
use Omeka\Entity\ItemSet;
use Omeka\Entity\Media;
use Omeka\Entity\Resource;
use Omeka\Entity\User;
use Omeka\Stdlib\ErrorStore;
use Omeka\Stdlib\Message;

class GroupAdapter extends AbstractEntityAdapter
{
    use QueryBuilderTrait;

    protected $sortFields = [
        'id' => 'id',
        'name' => 'name',
        // "group" is an alias of "name".
        'group' => 'name',
        'comment' => 'comment',
        // For info.
        // 'count' => 'count',
        // 'users' => 'users',
        // 'resources' => 'resources',
        // 'item_sets' => 'item_sets',
        // 'items' => 'items',
        // 'media' => 'media',
        // 'recent' => 'recent',
    ];

    public function getResourceName()
    {
        return 'groups';
    }

    public function getRepresentationClass()
    {
        return GroupRepresentation::class;
    }

    public function getEntityClass()
    {
        return Group::class;
    }

    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ) {
        if ($this->shouldHydrate($request, 'o:name')) {
            $name = $request->getValue('o:name');
            if (!is_null($name)) {
                $name = trim($name);
                $entity->setName($name);
            }
        }
        if ($this->shouldHydrate($request, 'o:comment')) {
            $comment = $request->getValue('o:comment');
            if (!is_null($comment)) {
                $comment = trim($comment);
                $entity->setComment($comment);
            }
        }
    }

    public function validateRequest(Request $request, ErrorStore $errorStore)
    {
        $data = $request->getContent();
        if (array_key_exists('o:name', $data)) {
            $result = $this->validateName($data['o:name'], $errorStore);
        }
    }

    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore)
    {
        $name = $entity->getName();
        $result = $this->validateName($name, $errorStore);
        if (!$this->isUnique($entity, ['name' => $name])) {
            $errorStore->addError('o:name', new Message(
                'The name "%s" is already taken.', // @translate
                $name
            ));
        }
    }

    /**
     * Validate a name.
     *
     * @param string $name
     * @param ErrorStore $errorStore
     * @return bool
     */
    protected function validateName($name, ErrorStore $errorStore)
    {
        $result = true;
        $sanitized = $this->sanitizeLightString($name);
        if (is_string($name) && $sanitized !== '') {
            $name = $sanitized;
            $sanitized = $this->sanitizeString($sanitized);
            if ($name !== $sanitized) {
                $errorStore->addError('o:name', new Message(
                    'The name "%s" contains forbidden characters.', // @translate
                    $name
                ));
                $result = false;
            }
            if (preg_match('~^[\d]+$~', $name)) {
                $errorStore->addError('o:name', 'A name can’t contain only numbers.'); // @translate
                $result = false;
            }
            $reserved = [
                'id', 'name', 'comment',
                'show', 'browse', 'add', 'edit', 'delete', 'delete-confirm', 'batch-edit', 'batch-edit-all',
            ];
            if (in_array(strtolower($name), $reserved)) {
                $errorStore->addError('o:name', 'A name cannot be a reserved word.'); // @translate
                $result = false;
            }
        } else {
            $errorStore->addError('o:name', 'A group must have a name.'); // @translate
            $result = false;
        }
        return $result;
    }

    public function buildQuery(QueryBuilder $qb, array $query)
    {
        if (isset($query['id'])) {
            $this->buildQueryValuesItself($qb, $query['id'], 'id');
        }

        if (isset($query['name'])) {
            $this->buildQueryValuesItself($qb, $query['name'], 'name');
        }

        if (isset($query['comment'])) {
            $this->buildQueryValuesItself($qb, $query['comment'], 'comment');
        }

        // All groups for these entities ("OR"). If multiple, mixed with "AND",
        // so, for mixed resources, use "resource_id".
        $mapResourceTypes = [
            'user_id' => User::class,
            'resource_id' => Resource::class,
            'item_set_id' => ItemSet::class,
            'item_id' => Item::class,
            'media_id' => Media::class,
        ];
        $subQueryKeys = array_intersect_key($mapResourceTypes, $query);
        foreach ($subQueryKeys as $queryKey => $resourceType) {
            if ($queryKey === 'user_id') {
                $groupEntity = GroupUser::class;
                $groupEntityColumn = 'user';
            } else {
                $groupEntity = GroupResource::class;
                $groupEntityColumn = 'resource';
            }
            $entities = is_array($query[$queryKey]) ? $query[$queryKey] : [$query[$queryKey]];
            $entities = array_filter($entities, 'is_numeric');
            if (empty($entities)) {
                continue;
            }
            $groupEntityAlias = $this->createAlias();
            $entityAlias = $this->createAlias();
            $qb
                // Note: This query may be used if the annotation is set in
                // core on Resource. In place, the relation is recreated.
                // ->innerJoin(
                //     $this->getEntityClass() . ($queryKey === 'user_id' ?  '.users' : '.resources'),
                //     $entityAlias, 'WITH',
                //     $qb->expr()->in("$entityAlias.id", $this->createNamedParameter($qb, $entities))
                // );
                ->innerJoin(
                    $groupEntity,
                    $groupEntityAlias,
                    'WITH',
                    $qb->expr()->andX(
                        $qb->expr()->eq($groupEntityAlias . '.group', $this->getEntityClass() . '.id'),
                        $qb->expr()->in(
                            $groupEntityAlias . '.' . $groupEntityColumn,
                            $this->createNamedParameter($qb, $entities)
                        )
                    )
                );
            // This check avoids bad result for bad request mixed ids.
            if (!in_array($queryKey, ['user_id', 'resource_id'])) {
                $resourceAlias = $this->createAlias();
                $qb
                    ->innerJoin(
                        $resourceType,
                        $resourceAlias,
                        'WITH',
                        $qb->expr()->eq(
                            $groupEntityAlias . '.resource',
                            $resourceAlias . '.id'
                        )
                    );
            }
        }

        if (array_key_exists('resource_type', $query)) {
            $mapResourceTypes = [
                'users' => User::class,
                'resources' => Resource::class,
                'item_sets' => ItemSet::class,
                'items' => Item::class,
                'media' => Media::class,
            ];
            if (isset($mapResourceTypes[$query['resource_type']])) {
                $entityJoinClass = $query['resource_type'] === 'users'
                    ? GroupUser::class
                    : GroupResource::class;
                $entityJoinAlias = $this->createAlias();
                $qb
                    ->linnerJoin(
                        $entityJoinClass,
                        $entityJoinAlias,
                        'WITH',
                        $qb->expr()->eq($entityJoinAlias . '.group', Group::class)
                    );
                if (!in_array($query['resource_type'], ['users', 'resources'])) {
                    $entityAlias = $this->createAlias();
                    $qb
                        ->innerJoin(
                            $mapResourceTypes[$query['resource_type']],
                            $entityAlias,
                            'WITH',
                            $qb->expr()->eq(
                                $entityJoinClass . '.resource',
                                $entityAlias . '.id'
                            )
                        );
                }
            } elseif ($query['resource_type'] !== '') {
                $qb
                    ->andWhere('1 = 0');
            }
        }
    }

    public function sortQuery(QueryBuilder $qb, array $query)
    {
        if (is_string($query['sort_by'])) {
            // TODO Use Doctrine native queries (here: ORM query builder).
            switch ($query['sort_by']) {
                // TODO Sort by count.
                case 'count':
                    break;
                // TODO Sort by user ids.
                case 'users':
                    break;
                // TODO Sort by resource ids.
                case 'resources':
                case 'item_sets':
                case 'items':
                case 'media':
                    break;
                case 'group':
                    $query['sort_by'] = 'name';
                    // No break.
                default:
                    parent::sortQuery($qb, $query);
                    break;
            }
        }
    }

    /**
     * Returns a sanitized string.
     *
     * @param string $string The string to sanitize.
     * @return string The sanitized string.
     */
    protected function sanitizeString($string)
    {
        // Quote is allowed.
        $string = strip_tags($string);
        // The first character is a space and the last one is a no-break space.
        $string = trim($string, ' /\\?<>:*%|"`&; ' . "\t\n\r");
        $string = preg_replace('/[\(\{]/', '[', $string);
        $string = preg_replace('/[\)\}]/', ']', $string);
        $string = preg_replace('/[[:cntrl:]\/\\\?<>\*\%\|\"`\&\;#+\^\$\s]/', ' ', $string);
        return trim(preg_replace('/\s+/', ' ', $string));
    }

    /**
     * Returns a light sanitized string.
     *
     * @param string $string The string to sanitize.
     * @return string The sanitized string.
     */
    protected function sanitizeLightString($string)
    {
        return trim(preg_replace('/\s+/', ' ', $string));
    }
}
