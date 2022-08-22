<?php declare(strict_types=1);

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

    protected $scalarFields = [
        'id' => 'id',
        'name' => 'name',
        // "group" is an alias of "name".
        'group' => 'name',
        'comment' => 'comment',
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

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

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
                //     $alias . ($queryKey === 'user_id' ?  '.users' : '.resources'),
                //     $entityAlias, 'WITH',
                //     $expr->in("$entityAlias.id", $this->createNamedParameter($qb, $entities))
                // );
                ->innerJoin(
                    $groupEntity,
                    $groupEntityAlias,
                    'WITH',
                    $expr->andX(
                        $expr->eq($groupEntityAlias . '.group', 'omeka_root.id'),
                        $expr->in(
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
                        $expr->eq(
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
                    ->innerJoin(
                        $entityJoinClass,
                        $entityJoinAlias,
                        'WITH',
                        $expr->eq(
                            "$entityJoinAlias.group",
                            'omeka_root'
                        )
                    );
                if (!in_array($query['resource_type'], ['users', 'resources'])) {
                    $entityAlias = $this->createAlias();
                    $qb
                        ->innerJoin(
                            $mapResourceTypes[$query['resource_type']],
                            $entityAlias,
                            'WITH',
                            $expr->eq(
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

    public function sortQuery(QueryBuilder $qb, array $query): void
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
                    // no break.
                default:
                    parent::sortQuery($qb, $query);
                    break;
            }
        }
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
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

    public function validateRequest(Request $request, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        if (array_key_exists('o:name', $data)) {
            $this->validateName($data['o:name'], $errorStore);
        }
    }

    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore): void
    {
        $name = $entity->getName();
        $this->validateName($name, $errorStore);
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
    protected function validateName($name, ErrorStore $errorStore): bool
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

    /**
     * Returns a sanitized string.
     *
     * @param string $string The string to sanitize.
     * @return string The sanitized string.
     */
    protected function sanitizeString($string): string
    {
        // Quote is allowed.
        $string = strip_tags((string) $string);
        // The first character is a space and "a0" is a no-break space.
        $string = trim($string, " /\\?<>:*%|\"`&;\u{a0}\t\n\r");
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
    protected function sanitizeLightString($string): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $string));
    }
}
