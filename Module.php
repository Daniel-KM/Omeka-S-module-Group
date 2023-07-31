<?php declare(strict_types=1);
/*
 * Group
 *
 * Add groups to users and resources to manage the access rights and the
 * resource visibility in a more flexible way.
 *
 * @copyright Daniel Berthereau, 2017-2023
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */
namespace Group;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Doctrine\ORM\Events;
use Generic\AbstractModule;
use Group\Controller\Admin\GroupController;
use Group\Db\Event\Listener\DetachOrphanGroupEntities;
use Group\Entity\Group;
use Group\Entity\GroupResource;
use Group\Entity\GroupUser;
use Group\Form\Element\GroupSelect;
use Group\Form\SearchForm;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Adapter\ItemAdapter;
use Omeka\Api\Adapter\ItemSetAdapter;
use Omeka\Api\Adapter\MediaAdapter;
use Omeka\Api\Adapter\UserAdapter;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Entity\Item;
use Omeka\Entity\ItemSet;
use Omeka\Entity\Media;
use Omeka\Entity\Resource;
use Omeka\Entity\User;

/**
 * Group
 *
 * Add groups to users and resources to manage the access in a more flexible way.
 */
class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        $this->addAclRules();

        // Allows to manage batch processes.
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $entityManager->getEventManager()->addEventListener(
            Events::preFlush,
            new DetachOrphanGroupEntities
        );
    }

    /**
     * Add ACL rules for this module.
     */
    protected function addAclRules(): void
    {
        /** @var \Omeka\Permissions\Acl $acl */
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        if (!$acl->hasRole('guest')) {
            $acl->addRole('guest');
        }
        if (!$acl->hasRole('guest_private')) {
            $acl->addRole('guest_private');
        }

        // Everybody can read own groups.
        $roles = $acl->getRoles();
        $adminRoles = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
        ];

        // TODO Remove right to read own groups for non-admin users?

        $acl
            ->allow(
                null,
                [
                    \Group\Entity\Group::class,
                    \Group\Entity\GroupResource::class,
                    \Group\Api\Adapter\GroupAdapter::class,
                ],
                ['search', 'read']
            )
            // TODO Add a permission to limit to read to own groups?
            ->allow(
                $roles,
                [
                    \Group\Entity\GroupUser::class,
                ],
                ['search', 'read']
            )

            // Only admin can manage groups.
            ->allow(
                $adminRoles,
                [\Group\Entity\Group::class],
                ['read', 'create', 'update', 'delete']
            )
            ->allow(
                $adminRoles,
                [\Group\Entity\GroupUser::class, \Group\Entity\GroupResource::class],
                // The right "assign" is used to display the form or not.
                ['read', 'create', 'update', 'delete', 'assign']
            )
            ->allow(
                $adminRoles,
                [\Group\Api\Adapter\GroupAdapter::class],
                ['search', 'read', 'create', 'update', 'delete']
            )
            ->allow(
                $adminRoles,
                [\Group\Controller\Admin\GroupController::class],
                ['show', 'browse', 'add', 'edit', 'delete', 'delete-confirm']
            );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $recursiveItemSets = !empty($config['group']['config']['group_recursive_item_sets']);
        $recursiveItems = !empty($config['group']['config']['group_recursive_items']);

        // Add the Group term definition.
        $sharedEventManager->attach(
            '*',
            'api.context',
            [$this, 'filterApiContext']
        );

        // Bypass the core filter for media (detach two events of Omeka\Module).
        // The listeners can't be cleared without a module weighting system.
        $listeners = $sharedEventManager->getListeners([MediaAdapter::class], 'api.search.query');
        $sharedEventManager->detach(
            [$listeners[1][0][0], 'filterMedia'],
            MediaAdapter::class
        );
        $sharedEventManager->attach(
            MediaAdapter::class,
            'api.search.query',
            [$this, 'filterMedia'],
            100
        );
        $sharedEventManager->attach(
            MediaAdapter::class,
            'api.find.query',
            [$this, 'filterMedia'],
            100
        );

        // Add the group part to the representation.
        $representations = [
            UserRepresentation::class,
            ItemSetRepresentation::class,
            ItemRepresentation::class,
            MediaRepresentation::class,
        ];
        foreach ($representations as $representation) {
            $sharedEventManager->attach(
                $representation,
                'rep.resource.json',
                [$this, 'filterEntityJsonLd']
            );
        }

        $adapters = [
            UserAdapter::class,
            ItemSetAdapter::class,
            ItemAdapter::class,
            MediaAdapter::class,
        ];
        foreach ($adapters as $adapter) {
            // Add the group filter to the search.
            $sharedEventManager->attach(
                $adapter,
                'api.search.query',
                [$this, 'searchQuery']
            );

            // The event "api.*.post" is used to avoid some flush issues.
            $sharedEventManager->attach(
                $adapter,
                'api.create.post',
                [$this, 'handleCreatePost']
            );
            $sharedEventManager->attach(
                $adapter,
                'api.update.post',
                [$this, 'handleUpdatePost']
            );
            // Required for partial batch, since requests are filtered by core.
            $sharedEventManager->attach(
                $adapter,
                'api.batch_update.post',
                [$this, 'handleBatchUpdatePost']
            );
        }
        // The deletion is managed automatically when not recursive.
        if ($recursiveItemSets) {
            $sharedEventManager->attach(
                ItemSetAdapter::class,
                'api.delete.pre',
                [$this, 'handleRecursiveDeleteItemSetPre']
            );
        }

        // Add headers to group views.
        $sharedEventManager->attach(
            GroupController::class,
            'view.show.before',
            [$this, 'addHeadersAdmin']
        );
        $sharedEventManager->attach(
            GroupController::class,
            'view.browse.before',
            [$this, 'addHeadersAdmin']
        );

        // Add the group element form to the user form.
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_elements',
            [$this, 'addUserFormElement']
        );
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_input_filters',
            [$this, 'addUserFormFilter']
        );
        // FIXME Use the autoset of the values (in a fieldset) and remove this.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\User',
            'view.edit.form.before',
            [$this, 'addUserFormValue']
        );

        // Add the show groups to the show admin pages.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\User',
            'view.show.after',
            [$this, 'viewShowAfterUser']
        );

        // Add the groups form to the resource batch edit form.
        $sharedEventManager->attach(
            \Omeka\Form\UserBatchUpdateForm::class,
            'form.add_elements',
            [$this, 'addBatchUpdateFormElement']
        );
        $sharedEventManager->attach(
            \Omeka\Form\ResourceBatchUpdateForm::class,
            'form.add_elements',
            [$this, 'addBatchUpdateFormElement']
        );

        if ($recursiveItemSets) {
            $controllers = [
                'Omeka\Controller\Admin\ItemSet',
            ];
        } elseif ($recursiveItems) {
            $controllers = [
                'Omeka\Controller\Admin\ItemSet',
                'Omeka\Controller\Admin\Item',
            ];
        } else {
            $controllers = [
                'Omeka\Controller\Admin\ItemSet',
                'Omeka\Controller\Admin\Item',
                'Omeka\Controller\Admin\Media',
            ];
        }
        foreach ($controllers as $controller) {
            // Add the group element form to the resource form.
            $sharedEventManager->attach(
                $controller,
                'view.add.section_nav',
                [$this, 'addTab']
            );
            $sharedEventManager->attach(
                $controller,
                'view.edit.section_nav',
                [$this, 'addTab']
            );
            $sharedEventManager->attach(
                $controller,
                'view.add.form.after',
                [$this, 'displayGroupResourceForm']
            );
            $sharedEventManager->attach(
                $controller,
                'view.edit.form.after',
                [$this, 'displayGroupResourceForm']
            );
        }

        $controllers = [
            'Omeka\Controller\Admin\ItemSet',
            'Omeka\Controller\Admin\Item',
            'Omeka\Controller\Admin\Media',
        ];
        foreach ($controllers as $controller) {
            // Add the show groups to the resource show admin pages.
            $sharedEventManager->attach(
                $controller,
                'view.show.section_nav',
                [$this, 'addTab']
            );

            // Add the show groups to the show admin pages.
            $sharedEventManager->attach(
                $controller,
                'view.show.after',
                [$this, 'viewShowAfterResource']
            );
        }

        $controllers = [
            'Omeka\Controller\Admin\User',
            'Omeka\Controller\Admin\ItemSet',
            'Omeka\Controller\Admin\Item',
            'Omeka\Controller\Admin\Media',
        ];
        foreach ($controllers as $controller) {
            // Add the show groups to the browse admin pages (details).
            $sharedEventManager->attach(
                $controller,
                'view.details',
                [$this, 'viewDetails']
            );

            // Filter the search filters for the advanced search pages.
            $sharedEventManager->attach(
                $controller,
                'view.search.filters',
                [$this, 'filterSearchFilters']
            );
        }
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $this->warnConfig();
        return '';
    }

    public function filterApiContext(Event $event): void
    {
        $context = $event->getParam('context');
        $context['o-module-group'] = 'http://omeka.org/s/vocabs/module/group#';
        $event->setParam('context', $context);
    }

    /**
     * Filter media belonging to private items.
     *
     * @see \Omeka\Module\Module::filterMedia()
     * @param Event $event
     */
    public function filterMedia(Event $event): void
    {
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');
        if ($acl->userIsAllowed('Omeka\Entity\Resource', 'view-all')) {
            return;
        }

        /** @var \Omeka\Api\Adapter\MediaAdapter $adapter */
        $adapter = $event->getTarget();
        $itemAlias = $adapter->createAlias();

        $qb = $event->getParam('queryBuilder');
        $expr = $qb->expr();

        $qb->innerJoin('omeka_root.item', $itemAlias);

        // Users can view media they do not own that belong to public items.
        $expression = $expr->eq("$itemAlias.isPublic", true);

        $identity = $services
            ->get('Omeka\AuthenticationService')->getIdentity();

        if ($identity) {
            // Prepare the specific part to check groups.
            $identityParam = $adapter->createNamedParameter($qb, $identity);
            $groupResourceAlias = $adapter->createAlias();
            $groupUserAlias = $adapter->createAlias();
            $qb->leftJoin(
                GroupResource::class,
                $groupResourceAlias,
                'WITH',
                "$groupResourceAlias.resource = $itemAlias.id"
            );
            $qb->leftJoin(
                GroupUser::class,
                $groupUserAlias,
                'WITH',
                "$groupUserAlias.user = $identityParam AND $groupResourceAlias.group = $groupUserAlias.group"
            );

            $expression = $expr->orX(
                $expression,
                // Users can view all media they own.
                $expr->eq(
                    "$itemAlias.owner",
                    $identityParam
                ),
                // Users can view media with at least one group in common.
                $expr->eq(
                    "$groupResourceAlias.group",
                    "$groupUserAlias.group"
                )
            );
        }

        $qb->andWhere($expression);
    }

    /**
     * Add the groups data to the resource JSON-LD.
     *
     * @param Event $event
     */
    public function filterEntityJsonLd(Event $event): void
    {
        // The groups are not shown to public, only admin users.
        $services = $this->getServiceLocator();
        $controllerPlugins = $services->get('ControllerPluginManager');
        $listGroups = $controllerPlugins->get('listGroups');

        $resource = $event->getTarget();
        $jsonLd = $event->getParam('jsonLd');
        $groups = $listGroups($resource, 'reference');
        $jsonLd['o-module-group:group'] = array_values($groups);
        $event->setParam('jsonLd', $jsonLd);
    }

    public function searchQuery(Event $event): void
    {
        $query = $event->getParam('request')->getContent();

        if (!empty($query['has_groups'])) {
            /** @var \Omeka\Api\Adapter\AbstractEntityAdapter $adapter */
            $adapter = $event->getTarget();
            $qb = $event->getParam('queryBuilder');
            $expr = $qb->expr();

            $groupEntityAlias = $adapter->createAlias();
            $entityAlias = 'omeka_root';
            if ($adapter->getResourceName() === 'users') {
                $groupEntity = GroupUser::class;
                $groupEntityColumn = 'user';
            } else {
                $groupEntity = GroupResource::class;
                $groupEntityColumn = 'resource';
            }
            $qb->innerJoin(
                $groupEntity,
                $groupEntityAlias,
                'WITH',
                $expr->eq($groupEntityAlias . '.' . $groupEntityColumn, $entityAlias . '.id')
            );
        }

        if (!empty($query['group'])) {
            $groups = $this->cleanStrings($query['group']);
            if (empty($groups)) {
                return;
            }
            $isId = preg_match('~^\d+$~', reset($groups));

            /** @var \Omeka\Api\Adapter\AbstractEntityAdapter $adapter */
            $adapter = $event->getTarget();
            $qb = $event->getParam('queryBuilder');
            $expr = $qb->expr();

            $entityAlias = 'omeka_root';
            if ($adapter->getResourceName() === 'users') {
                $groupEntity = GroupUser::class;
                $groupEntityColumn = 'user';
            } else {
                $groupEntity = GroupResource::class;
                $groupEntityColumn = 'resource';
            }
            // All resources with any group ("OR").
            // TODO The resquest is working, but it needs a format for querying.
            /*
            $groupEntityAlias = $adapter->createAlias();
            $groupAlias = $adapter->createAlias();
            $qb
                ->innerJoin(
                    $groupEntity,
                    $groupEntityAlias,
                    'WITH',
                    "$groupEntityAlias.$groupEntityColumn = $entityAlias.id"
                );
            if ($isId) {
                $qb
                    ->andWhere($expr->in($groupEntityAlias . '.group', $groups));
            } else {
                $qb
                    ->innerJoin(
                        Group::class,
                        $groupAlias,
                        'WITH',
                        "$groupEntityAlias.group = $groupAlias.id"
                    )
                    ->andWhere($expr->in($groupAlias . '.name', $groups));
            }
            */
            // All resources with all groups ("AND").
            foreach ($groups as $group) {
                $groupEntityAlias = $adapter->createAlias();
                $groupAlias = $adapter->createAlias();
                $qb
                    // Simulate a cross join, not managed by doctrine.
                    ->innerJoin(
                        Group::class,
                        $groupAlias,
                        'WITH', '1 = 1'
                    )
                    ->innerJoin(
                        $groupEntity,
                        $groupEntityAlias,
                        'WITH',
                        $expr->andX(
                            $expr->eq($groupEntityAlias . '.' . $groupEntityColumn, $entityAlias . '.id'),
                            $expr->eq($groupEntityAlias . '.group', $groupAlias . '.id')
                        )
                    );
                if ($isId) {
                    $qb
                        ->andWhere($expr->eq(
                            $groupAlias . '.id',
                            $adapter->createNamedParameter($qb, $group)
                        ));
                } else {
                    $qb
                        ->andWhere($expr->eq(
                            $groupAlias . '.name',
                            $adapter->createNamedParameter($qb, $group)
                        ));
                }
            }
        }
    }

    /**
     * Handle hydration for groups data after hydration of an entity.
     *
     * @todo Clarify and use acl only.
     * @param Event $event
     */
    public function handleCreatePost(Event $event): void
    {
        $resourceAdapter = $event->getTarget();
        $resourceType = $resourceAdapter->getEntityClass();
        if (!$this->checkAcl($resourceType, 'create')) {
            return;
        }

        $response = $event->getParam('response');
        $request = $response->getRequest();
        if (!$resourceAdapter->shouldHydrate($request, 'o-module-group:group')) {
            return;
        }

        $resource = $response->getContent();
        $submittedGroups = $request->getValue('o-module-group:group') ?: [];

        $aboveGroups = $this->takeGroupsFromAbove($resourceType);
        $recursive = $this->isRecursive($resourceType);

        $services = $this->getServiceLocator();
        $controllerPlugins = $services->get('ControllerPluginManager');
        $applyGroups = $controllerPlugins->get('applyGroups');
        $applyGroups($resource, $submittedGroups, 'append', $aboveGroups, $recursive);

        // Since we use api.*.post, the entity manager should be flushed.
        $entityManager = $services->get('Omeka\EntityManager');
        $entityManager->flush();
    }

    /**
     * Handle hydration for groups data after hydration of an entity.
     *
     * @todo Clarify and use acl only.
     * @param Event $event
     */
    public function handleUpdatePost(Event $event): void
    {
        $resourceAdapter = $event->getTarget();
        $resourceType = $resourceAdapter->getEntityClass();
        if (!$this->checkAcl($resourceType, 'update')) {
            return;
        }

        $request = $event->getParam('request');

        $aboveGroups = $this->takeGroupsFromAbove($resourceType);
        $recursive = $this->isRecursive($resourceType);

        // Manage partial update (and avoid a batch issue, without clear).
        if ($recursive) {
            if (!$resourceAdapter->shouldHydrate($request, 'o:item_set')) {
                return;
            }
        } else {
            if (!$resourceAdapter->shouldHydrate($request, 'o-module-group:group')) {
                return;
            }
        }

        $resource = $event->getParam('response')->getContent();
        $submittedGroups = $request->getValue('o-module-group:group') ?: [];

        $services = $this->getServiceLocator();
        $controllerPlugins = $services->get('ControllerPluginManager');
        $applyGroups = $controllerPlugins->get('applyGroups');
        $applyGroups($resource, $submittedGroups, 'replace', $aboveGroups, $recursive);

        // Since we use api.*.post, the entity manager should be flushed.
        $entityManager = $services->get('Omeka\EntityManager');
        $entityManager->flush();
    }

    /**
     * Handle hydration for groups data after batch update of an entity.
     *
     * @todo Clarify and use acl only.
     * @param Event $event
     */
    public function handleBatchUpdatePost(Event $event): void
    {
        $resourceAdapter = $event->getTarget();
        $resourceType = $resourceAdapter->getEntityClass();
        if (!$this->checkAcl($resourceType, 'update')) {
            return;
        }

        $response = $event->getParam('response');
        $request = $response->getRequest();
        $data = $request->getContent();
        if (!empty($data['remove_groups'])) {
            $groups = $data['remove_groups'];
            $collectionAction = 'remove';
        } elseif (!empty($data['add_groups'])) {
            $groups = $data['add_groups'];
            $collectionAction = 'append';
        } else {
            return;
        }

        $aboveGroups = $this->takeGroupsFromAbove($resourceType);
        $recursive = $this->isRecursive($resourceType);

        $resources = $event->getParam('response')->getContent();

        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $controllerPlugins = $services->get('ControllerPluginManager');
        $applyGroups = $controllerPlugins->get('applyGroups');
        foreach ($resources as $resource) {
            // Resource cannot be managed directly by the entity manager, or
            // there may be a risk of duplicate. Merge is not enough.
            $resource = $entityManager->find($resourceType, $resource->getId());
            $applyGroups($resource, $groups, $collectionAction, $aboveGroups, $recursive);
        }

        // Since we use api.*.post, the entity manager should be flushed.
        $entityManager->flush();
        // The clear avoids issues when there are groups removed and appended
        // during the same batch process, for item sets with recursive.
        // TODO Check if entity manager clear is still useful.
        if ($recursive && in_array($resourceType, [ItemSet::class, Item::class])) {
            $entityManager->clear();
        }
    }

    /**
     * Handle recursive deletion for groups data before deletion of an entity.
     *
     * @todo Clarify and use acl only.
     * @param Event $event
     */
    public function handleRecursiveDeleteItemSetPre(Event $event): void
    {
        $resourceAdapter = $event->getTarget();
        $resourceType = $resourceAdapter->getEntityClass();
        if (!$this->checkAcl($resourceType, 'delete')) {
            return;
        }

        $request = $event->getParam('request');
        if (!$resourceAdapter->shouldHydrate($request, 'o-module-group:group')) {
            return;
        }

        $resource = $resourceAdapter->findEntity($request->getId());

        $services = $this->getServiceLocator();
        $controllerPlugins = $services->get('ControllerPluginManager');
        $applyGroups = $controllerPlugins->get('applyGroups');
        $applyGroups($resource, [], 'replace', false, true);

        // Since we use api.*.post, the entity manager should be flushed.
        $entityManager = $services->get('Omeka\EntityManager');
        $entityManager->flush();
    }

    /**
     * Add the headers for admin management.
     *
     * @param Event $event
     */
    public function addHeadersAdmin(Event $event): void
    {
        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()->appendStylesheet($assetUrl('css/group.css', 'Group'));
        $view->headScript()->appendFile($assetUrl('js/group.js', 'Group'), 'text/javascript', ['defer' => 'defer']);
    }

    public function addUserFormElement(Event $event): void
    {
        // Groups are for admins only.
        if (!$this->getServiceLocator()->get('Omeka\Status')->isAdminRequest()) {
            return;
        }

        // Check rights: only admins can read and update groups.
        if (!$this->checkAcl(User::class, 'update') || !$this->checkAcl(User::class, 'assign')) {
            return;
        }

        $form = $event->getTarget();
        $form->get('user-information')->add([
            'name' => 'o-module-group:group',
            'type' => GroupSelect::class,
            'options' => [
                'label' => 'Groups', // @translate
                'chosen' => true,
            ],
            'attributes' => [
                'multiple' => true,
            ],
        ]);
    }

    public function addUserFormFilter(Event $event): void
    {
        // Groups are for admins only.
        if (!$this->getServiceLocator()->get('Omeka\Status')->isAdminRequest()) {
            return;
        }

        // Check rights: only admins can read and update groups.
        if (!$this->checkAcl(User::class, 'update') || !$this->checkAcl(User::class, 'assign')) {
            return;
        }

        // TODO Add a validator for the groups of user.
    }

    public function addUserFormValue(Event $event): void
    {
        // Groups are for admins only. Other rights are checked in the listing.
        if (!$this->getServiceLocator()->get('Omeka\Status')->isAdminRequest()) {
            return;
        }

        // Check rights: only admins can read and update groups.
        if (!$this->checkAcl(User::class, 'update') || !$this->checkAcl(User::class, 'assign')) {
            return;
        }

        $user = $event->getTarget()->vars()->user;
        $values = $this->listGroups($user, 'reference');
        $form = $event->getParam('form');
        $form->get('user-information')->get('o-module-group:group')
            ->setAttribute('value', array_keys($values));
    }

    public function addBatchUpdateFormElement(Event $event): void
    {
        $form = $event->getTarget();
        $resourceType = $form->getOption('resource_type');
        if ($resourceType) {
            $resourcesTypes = [
                'itemSet' => ItemSet::class,
                'item' => Item::class,
                'media' => Media::class,
            ];
            $resourceType = $resourcesTypes[$resourceType];
        } else {
            $resourceType = User::class;
        }

        $aboveGroups = $this->takeGroupsFromAbove($resourceType);
        if ($aboveGroups) {
            return;
        }

        $services = $this->getServiceLocator();
        $isUser = $resourceType === User::class;
        $groupEntityClass = $isUser ? GroupUser::class : GroupResource::class;

        $acl = $services->get('Omeka\Acl');
        if ($acl->userIsAllowed($groupEntityClass, 'delete')) {
            $form->add([
                'name' => 'remove_groups',
                'type' => GroupSelect::class,
                'options' => [
                    'label' => 'Remove groups', // @translate
                    'chosen' => true,
                ],
                'attributes' => [
                    'id' => 'remove-groups',
                    'multiple' => true,
                    'data-collection-action' => 'remove',
                ],
            ]);
        }
        if ($acl->userIsAllowed($groupEntityClass, 'create')) {
            $form->add([
                'name' => 'add_groups',
                'type' => GroupSelect::class,
                'options' => [
                    'label' => 'Add groups', // @translate
                    'chosen' => true,
                ],
                'attributes' => [
                    'id' => 'add-groups',
                    'multiple' => true,
                    'data-collection-action' => 'append',
                ],
            ]);
        }
    }

    /**
     * Add the tab to section navigation.
     */
    public function addTab(Event $event): void
    {
        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['groups'] = $translator->translate('Groups'); // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    /**
     * Display the grouping form.
     *
     * @todo Merge with user groups form.
     */
    public function displayGroupResourceForm(Event $event): void
    {
        $operation = $event->getName();
        if (!$this->checkAcl(Resource::class, $operation === 'view.add.form.after' ? 'create' : 'update')
            || !$this->checkAcl(Resource::class, 'assign')
        ) {
            $this->viewShowAfterResource($event);
            return;
        }

        $vars = $event->getTarget()->vars();
        // Manage add/edit form.
        if (isset($vars->item)) {
            $vars->offsetSet('resource', $vars->item);
        } elseif (isset($vars->itemSet)) {
            $vars->offsetSet('resource', $vars->itemSet);
        } elseif (isset($vars->media)) {
            $vars->offsetSet('resource', $vars->media);
        } else {
            $vars->offsetSet('resource', null);
            $vars->offsetSet('groups', []);
        }
        if ($vars->resource) {
            $vars->offsetSet('groups', $this->listGroups($vars->resource, 'representation'));
        }

        echo $event->getTarget()->partial(
            'common/admin/groups-resource-form'
        );
    }

    /**
     * Display the groups for a user.
     */
    public function viewShowAfterUser(Event $event): void
    {
        $resource = $event->getTarget()->vars()->user;
        $this->displayViewAdmin($event, $resource, false);
    }

    /**
     * Display the groups for a resource.
     */
    public function viewShowAfterResource(Event $event): void
    {
        echo '<div id="groups" class="section">';
        $resource = $event->getTarget()->vars()->resource;
        $this->displayViewAdmin($event, $resource, false);
        echo '</div>';
    }

    /**
     * Add details for a resource.
     */
    public function viewDetails(Event $event): void
    {
        $resource = $event->getTarget()->resource;
        $this->displayViewAdmin($event, $resource, true);
    }

    /**
     * Display an admin view.
     *
     * @param Event $event
     * @param AbstractEntityRepresentation $resource
     * @param bool $listAsDiv Return the list with div, not ul.
     */
    protected function displayViewAdmin(
        Event $event,
        AbstractEntityRepresentation $resource = null,
        $listAsDiv = false
    ): void {
        // TODO Add an acl check for right to view groups (controller level).
        $isUser = $resource && $resource->getControllerName() === 'user';
        $groups = $this->listGroups($resource, 'representation');
        $partial = $listAsDiv
            ? 'common/admin/groups-resource'
            : 'common/admin/groups-resource-list';
        echo $event->getTarget()->partial(
            $partial,
            [
                'resource' => $resource,
                'groups' => $groups,
                'isUser' => $isUser,
            ]
        );
    }

    /**
     * Display the advanced search form via partial.
     */
    public function displayAdvancedSearch(Event $event): void
    {
        $services = $this->getServiceLocator();
        $formElementManager = $services->get('FormElementManager');
        $form = $formElementManager->get(SearchForm::class);
        $form->init();

        $view = $event->getTarget();
        $query = $event->getParam('query', []);
        // $resourceType = $event->getParam('resourceType');

        $hasGroups = !empty($query['has_groups']);
        $groups = empty($query['group']) ? '' : implode(', ', $this->cleanStrings($query['group']));

        $formData = [];
        $formData['has_groups'] = $hasGroups;
        $formData['group'] = $groups;
        $form->setData($formData);

        $vars = $view->vars();
        $vars->offsetSet('searchGroupForm', $form);

        echo $view->partial(
            'common/admin/groups-advanced-search'
        );
    }

    /**
     * Filter search filters.
     */
    public function filterSearchFilters(Event $event): void
    {
        $translate = $event->getTarget()->plugin('translate');
        $filters = $event->getParam('filters');
        $query = $event->getParam('query', []);
        if (!empty($query['has_groups'])) {
            $filterLabel = $translate('Has groups'); // @translate
            $filterValue = $translate('true');
            $filters[$filterLabel][] = $filterValue;
        }
        if (!empty($query['group'])) {
            $filterLabel = $translate('Group'); // @translate
            $filterValue = $this->cleanStrings($query['group']);
            $filters[$filterLabel] = $filterValue;
        }
        $event->setParam('filters', $filters);
    }

    protected function warnConfig(): void
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $translator = $services->get('MvcTranslator');
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        $message = new \Omeka\Stdlib\Message(
            $translator->translate('The settings should be set in the file "config/local.config.php" of Omeka. See the file module.config.php of the module and readme.') // @translate
        );
        $messenger->addWarning($message);

        $recursiveItemSets = !empty($config['group']['config']['group_recursive_item_sets']);
        $message = new \Omeka\Stdlib\Message(
            $translator->translate('Recursive item sets: %s'), // @translate
            $recursiveItemSets
                ? $translator->translate('yes') // @translate
               : $translator->translate('no') // @translate
        );
        $messenger->addSuccess($message);

        if ($recursiveItemSets) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The groups for resources can be set only by item sets.') // @translate
            );
            $messenger->addSuccess($message);
        }

        $recursiveItems = !empty($config['group']['config']['group_recursive_items']);
        $message = new \Omeka\Stdlib\Message(
            $translator->translate('Recursive items: %s'), // @translate
            $recursiveItems
                ? $translator->translate('yes') // @translate
                : $translator->translate('no') // @translate
        );
        $messenger->addSuccess($message);

        if (!$recursiveItemSets && $recursiveItems) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The groups for medias can be set only at items level.') // @translate
            );
            $messenger->addSuccess($message);
        }
    }

    /**
     * Check rights to manage groups.
     */
    protected function checkAcl(string $resourceClass, string $privilege): bool
    {
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $groupEntity = $resourceClass === User::class
            ? GroupUser::class
            : GroupResource::class;
        return $acl->userIsAllowed($groupEntity, $privilege);
    }

    /**
     * Check if groups are applied from above.
     */
    protected function takeGroupsFromAbove(string$resourceClass): bool
    {
        switch ($resourceClass) {
            case ItemSet::class:
                return false;
            case Item::class:
                return !empty($this->getServiceLocator()->get('Config')['group']['config']['group_recursive_item_sets']);
            case Media::class:
                return !empty($this->getServiceLocator()->get('Config')['group']['config']['group_recursive_items']);
            case User::class:
            default:
                return false;
        }
    }

    /**
     * Check if groups apply recursively for resources below.
     */
    protected function isRecursive(string $resourceClass): bool
    {
        switch ($resourceClass) {
            case ItemSet::class:
                return !empty($this->getServiceLocator()->get('Config')['group']['config']['group_recursive_item_sets']);
            case Item::class:
                return !empty($this->getServiceLocator()->get('Config')['group']['config']['group_recursive_items']);
            case Media::class:
            case User::class:
            default:
                return false;
        }
    }

    /**
     * Helper to return groups of an entity, by group name.
     *
     * @param AbstractEntityRepresentation $resource
     * @param string $contentType "json" (default), "representation" or "reference".
     * @return \Group\Entity\Group[]
     */
    protected function listGroups(AbstractEntityRepresentation $resource = null, $contentType = null): array
    {
        if (is_null($resource) || empty($resource->id())) {
            return [];
        }

        $resourceJson = $resource->jsonSerialize();
        $list = empty($resourceJson['o-module-group:group'])
            ? []
            : $resourceJson['o-module-group:group'];

        $result = [];
        switch ($contentType) {
            case 'reference':
                foreach ($list as $entity) {
                    $result[$entity->name()] = $entity;
                }
                break;
            case 'representation':
                $api = $this->getServiceLocator()->get('Omeka\ApiManager');
                foreach ($list as $entity) {
                    $result[$entity->name()] = $api->read('groups', $entity->id())->getContent();
                }
                break;
            case 'json':
            default:
                $result = $list;
                break;
        }
        return  $result;
    }

    /**
     * Clean a list of alphanumeric strings, separated by a comma.
     *
     * @param array|string $strings
     * @return array
     */
    protected function cleanStrings($strings): array
    {
        if (!is_array($strings)) {
            $strings = explode(',', $strings);
        }
        return array_filter(array_map('trim', $strings));
    }
}
