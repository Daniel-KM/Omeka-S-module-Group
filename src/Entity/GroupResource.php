<?php declare(strict_types=1);

namespace Group\Entity;

use Omeka\Entity\Resource;

/**
 * User is a table in the core, so it is not annotable, so the join table is
 * declared as the entity GroupResource in order to bypass this issue.
 * This entity is available only by the orm, not by Omeka S.
 *
 * @Entity
 */
class GroupResource
{
    /**
     * @var Group
     *
     * @Id
     * @ManyToOne(
     *     targetEntity="Group\Entity\Group",
     *     inversedBy="groupResources",
     *     cascade={"persist"}
     * )
     * @JoinColumn(
     *     onDelete="cascade",
     *     nullable=false
     * )
     */
    protected $group;

    /**
     * @var Resource
     *
     * @Id
     * @ManyToOne(
     *     targetEntity="Omeka\Entity\Resource",
     *     cascade={"persist"}
     * )
     * @JoinColumn(
     *     onDelete="cascade",
     *     nullable=false
     * )
     */
    protected $resource;

    public function __construct(Group $group, Resource $resource)
    {
        $this->group = $group;
        $this->resource = $resource;
    }

    public function getGroup(): Group
    {
        return $this->group;
    }

    public function getResource(): Resource
    {
        return $this->resource;
    }

    public function __toString(): string
    {
        return json_encode([
            'group' => $this->getGroup()->getId(),
            'resource' => $this->getResource()->getId(),
        ]);
    }
}
