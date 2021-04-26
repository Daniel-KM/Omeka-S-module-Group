<?php declare(strict_types=1);

namespace Group\Entity;

use Omeka\Entity\User;

/**
 * User is a table in the core, so it is not annotable, so the join table is
 * declared as the entity GroupUser in order to bypass this issue.
 * This entity is available only by the orm, not by Omeka S.
 *
 * @Entity
 */
class GroupUser
{
    /**
     * @var Group
     *
     * @Id
     * @ManyToOne(
     *     targetEntity="Group\Entity\Group",
     *     inversedBy="groupUsers",
     *     cascade={"persist"}
     * )
     * @JoinColumn(
     *     onDelete="cascade",
     *     nullable=false
     * )
     */
    protected $group;

    /**
     * @var User
     *
     * @Id
     * @ManyToOne(
     *     targetEntity="Omeka\Entity\User",
     *     cascade={"persist"}
     * )
     * @JoinColumn(
     *     onDelete="cascade",
     *     nullable=false
     * )
     */
    protected $user;

    public function __construct(Group $group, User $user)
    {
        $this->group = $group;
        $this->user = $user;
    }

    public function getGroup(): Group
    {
        return $this->group;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function __toString(): string
    {
        return json_encode([
            'group' => $this->getGroup()->getId(),
            'user' => $this->getUser()->getId(),
        ]);
    }
}
