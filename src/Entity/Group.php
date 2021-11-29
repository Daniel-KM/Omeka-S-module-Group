<?php declare(strict_types=1);

namespace Group\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Omeka\Entity\AbstractEntity;

/**
 * A table with the name `group` may create an issue in Doctrine 2, so "groups"
 * is used to avoid quoting all queries.
 * @link https://stackoverflow.com/questions/14080720/doctrine2-does-not-escape-table-name-on-scheme-update
 * @link https://github.com/doctrine/doctrine2/issues/4247
 * @link https://github.com/doctrine/doctrine2/issues/5874
 *
 * @Entity
 * @Table(
 *     name="`groups`"
 * )
 */
class Group extends AbstractEntity
{
    /**
     * @var int
     *
     * @Id
     * @Column(
     *     type="integer"
     * )
     * @GeneratedValue
     */
    protected $id;

    /**
     * @var string
     *
     * Note: The limit of 190 is related to the format of the base (utf8mb4) and
     * to the fact that there is an index and the max index size is 767, so
     * 190 x 4 = 760.
     *
     * @Column(
     *     length=190,
     *     unique=true
     * )
     */
    protected $name;

    /**
     * @Column(
     *     name="`comment`",
     *     type="text",
     *     nullable=true
     * )
     */
    protected $comment;

    /* *
     * This relation cannot be set in the core, so it is not a doc block.
     *
     * @var ArrayCollection|User[]
     *
     * Many Groups have Many Users.
     * @ManyToMany(
     *     targetEntity="Omeka\Entity\User",
     *     mappedBy="group",
     *     inversedBy="user"
     * )
     * @JoinTable(
     *     name="group_user",
     *     joinColumns={
     *         @JoinColumn(
     *             name="group_id",
     *             referencedColumnName="id",
     *             onDelete="cascade",
     *             nullable=false
     *         )
     *     },
     *     inverseJoinColumns={
     *         @JoinColumn(
     *             name="user_id",
     *             referencedColumnName="id",
     *             onDelete="cascade",
     *             nullable=false
     *         )
     *     }
     * )
     */
    protected $users;

    /**
     * Because the relation cannot be annotated for the users in the core, the
     * join relation is declared here. This property is available only in orm,
     * not in Omeka S.
     *
     * @var ArrayCollection|GroupUser[]
     *
     * One Group has Many relations to User via GroupUsers.
     * @OneToMany(
     *     targetEntity="Group\Entity\GroupUser",
     *     mappedBy="group",
     *     cascade={"persist", "remove"}
     * )
     */
    protected $groupUsers;

    /* *
     * This relation cannot be set in the core, so it is not a doc block.
     *
     * @var Collection|Resource[]
     *
     * Many Groups have Many Resources.
     * @ManyToMany(
     *     targetEntity="Omeka\Entity\Resource",
     *     mappedBy="group",
     *     inversedBy="resource"
     * )
     * @JoinTable(
     *     name="group_resource",
     *     joinColumns={
     *         @JoinColumn(
     *             name="group_id",
     *             referencedColumnName="id",
     *             onDelete="cascade",
     *             nullable=false
     *         )
     *     },
     *     inverseJoinColumns={
     *         @JoinColumn(
     *             name="resource_id",
     *             referencedColumnName="id",
     *             onDelete="cascade",
     *             nullable=false
     *         )
     *     }
     * )
     */
    protected $resources;

    /**
     * Because the relation cannot be annotated for resources in the core, the
     * join relation is declared here. This property is available only in orm,
     * not in Omeka S.
     *
     * @var Collection|GroupResource[]
     *
     * One Group has Many relations to Resource via GroupResources.
     * @OneToMany(
     *     targetEntity="Group\Entity\GroupResource",
     *     mappedBy="group",
     *     cascade={"persist", "remove"}
     * )
     */
    protected $groupResources;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->groupUsers = new ArrayCollection();
        $this->resources = new ArrayCollection();
        $this->groupResources = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function setName($name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getUsers(): ArrayCollection
    {
        return $this->users;
    }

    public function getGroupUsers(): ArrayCollection
    {
        return $this->groupUsers;
    }

    public function getResources(): ArrayCollection
    {
        return $this->resources;
    }

    public function getGroupResources(): ArrayCollection
    {
        return $this->groupResources;
    }
}
