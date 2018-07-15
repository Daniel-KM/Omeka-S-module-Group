<?php
/*
 * Group
 *
 * Add groups to users and resources to manage the access rights and the
 * resource visibility in a more flexible way.
 *
 * Copyright Daniel Berthereau 2017
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

use Doctrine\ORM\Events;
use Group\Api\Adapter\GroupAdapter;
use Group\Controller\Admin\GroupController;
use Group\Db\Event\Listener\DetachOrphanGroupEntities;
use Group\Entity\Group;
use Group\Entity\GroupResource;
use Group\Entity\GroupUser;
use Group\Form\Element\GroupSelect;
use Group\Form\SearchForm;
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
use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Renderer\PhpRenderer;

/**
 * Group
 *
 * Add groups to users and resources to manage the access in a more flexible way.
 *
 * @copyright Daniel Berthereau, 2017-2018
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
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

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        // @todo Replace the two tables "group_user" and "group_resource" by one
        // "grouping" with one column "entity_type": it will simplify a lot of
        // thing, but will this improve performance (search with a ternary key)?
        // This will be checked if a new group of something is needed (for sites).
        $sql = <<<'SQL'
CREATE TABLE groups (
  id INT AUTO_INCREMENT NOT NULL,
  name VARCHAR(190) NOT NULL,
  comment LONGTEXT DEFAULT NULL,
  UNIQUE INDEX UNIQ_F06D39705E237E06 (name),
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE group_resource (
  group_id INT NOT NULL,
  resource_id INT NOT NULL,
  INDEX IDX_B5A1B869FE54D947 (group_id),
  INDEX IDX_B5A1B86989329D25 (resource_id),
  PRIMARY KEY(group_id, resource_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE group_user (
  group_id INT NOT NULL,
  user_id INT NOT NULL,
  INDEX IDX_A4C98D39FE54D947 (group_id),
  INDEX IDX_A4C98D39A76ED395 (user_id),
  PRIMARY KEY(group_id, user_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
ALTER TABLE group_resource ADD CONSTRAINT FK_B5A1B869FE54D947 FOREIGN KEY (group_id) REFERENCES groups (id) ON DELETE CASCADE;
ALTER TABLE group_resource ADD CONSTRAINT FK_B5A1B86989329D25 FOREIGN KEY (resource_id) REFERENCES resource (id) ON DELETE CASCADE;
ALTER TABLE group_user ADD CONSTRAINT FK_A4C98D39FE54D947 FOREIGN KEY (group_id) REFERENCES groups (id) ON DELETE CASCADE;
ALTER TABLE group_user ADD CONSTRAINT FK_A4C98D39A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE;
SQL;
        $connection = $serviceLocator->get('Omeka\Connection');
        $sqls = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($sqls as $sql) {
            $connection->exec($sql);
        }
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $sql = <<<'SQL'
DROP TABLE IF EXISTS `group_user`;
DROP TABLE IF EXISTS `group_resource`;
DROP TABLE IF EXISTS `groups`;
SQL;
        $connection = $serviceLocator->get('Omeka\Connection');
        $sqls = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($sqls as $sql) {
            $connection->exec($sql);
        }
    }

    /**
     * Add ACL rules for this module.
     */
    protected function addAclRules()
    {
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        // Everybody can read groups, but not view them.
        $roles = $acl->getRoles();
        $entityRights = ['read'];
        $adapterRights = ['search', 'read'];
        $acl->allow(
            null,
            [
                \Group\Entity\Group::class,
                \Group\Entity\GroupUser::class,
                \Group\Entity\GroupResource::class,
            ],
            $entityRights
        );
        // Deny access to the api for non admin.
        /*
        $acl->deny(
            null,
            [\Group\Api\Adapter\GroupAdapter::class],
            null
        );
        */

        // Only admin can manage groups.
        $adminRoles = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
        ];
        $acl->allow(
            $adminRoles,
            [\Group\Entity\Group::class],
            ['read', 'create', 'update', 'delete']
        );
        $acl->allow(
            $adminRoles,
            [\Group\Entity\GroupUser::class, \Group\Entity\GroupResource::class],
            // The right "assign" is used to display the form or not.
            ['read', 'create', 'update', 'delete', 'assign']
        );
        $acl->allow(
            $adminRoles,
            [\Group\Api\Adapter\GroupAdapter::class],
            ['search', 'read', 'create', 'update', 'delete']
        );
        $acl->allow(
            $adminRoles,
            [\Group\Controller\Admin\GroupController::class],
            ['show', 'browse', 'add', 'edit', 'delete', 'delete-confirm']
        );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $config = $services->get('Config');
        $recursiveItemSets = $config[strtolower(__NAMESPACE__)]['config']['group_recursive_item_sets'];
        $recursiveItems = $config[strtolower(__NAMESPACE__)]['config']['group_recursive_items'];

        // Add the Group term definition.
        $sharedEventManager->attach(
            '*',
            'api.context',
            function (Event $event) {
                $context = $event->getParam('context');
                $context['o-module-group'] = 'http://omeka.org/s/vocabs/module/group#';
                $event->setParam('context', $context);
            }
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
            \Omeka\Form\UserBatchUpdateForm::class,
            'form.add_input_filters',
            [$this, 'addBatchUpdateFormFilter']
        );
        $sharedEventManager->attach(
            \Omeka\Form\ResourceBatchUpdateForm::class,
            'form.add_elements',
            [$this, 'addBatchUpdateFormElement']
        );
        $sharedEventManager->attach(
            \Omeka\Form\ResourceBatchUpdateForm::class,
            'form.add_input_filters',
            [$this, 'addBatchUpdateFormFilter']
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
        $services = $this->getServiceLocator();
        $t = $services->get('MvcTranslator');
        return $t->translate('The settings are available in the file module.config.php of  the module. See readme.'); // @translate
    }

    /**
     * Filter media belonging to private items.
     *
     * @see \Omeka\Module::filterMedia()
     * @param Event $event
     */
    public function filterMedia(Event $event)
    {
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');
        if ($acl->userIsAllowed('Omeka\Entity\Resource', 'view-all')) {
            return;
        }

        $adapter = $event->getTarget();
        $itemAlias = $adapter->createAlias();
        $qb = $event->getParam('queryBuilder');
        $qb->innerJoin('Omeka\Entity\Media.item', $itemAlias);

        // Users can view media they do not own that belong to public items.
        $expression = $qb->expr()->eq("$itemAlias.isPublic", true);

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

            $expression = $qb->expr()->orX(
                $expression,
                // Users can view all media they own.
                $qb->expr()->eq(
                    "$itemAlias.owner",
                    $identityParam
                ),
                // Users can view media with at least one group in common.
                $qb->expr()->eq(
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
    public function filterEntityJsonLd(Event $event)
    {
        // The groups are not shown to public.
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        if (!$acl->userIsAllowed(GroupAdapter::class, 'search')
            && !$acl->userIsAllowed(GroupAdapter::class, 'read')
        ) {
            return;
        }

        $resource = $event->getTarget();
        $columnName = $this->columnNameOfRepresentation($resource);
        $jsonLd = $event->getParam('jsonLd');
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $groups = $api
            ->search('groups', [$columnName => $resource->id()], ['responseContent' => 'reference'])
            ->getContent();
        $jsonLd['o-module-group:group'] = $groups;
        $event->setParam('jsonLd', $jsonLd);
    }

    public function searchQuery(Event $event)
    {
        $query = $event->getParam('request')->getContent();

        if (!empty($query['has_groups'])) {
            $qb = $event->getParam('queryBuilder');
            $adapter = $event->getTarget();
            $groupEntityAlias = $adapter->createAlias();
            $entityAlias = $adapter->getEntityClass();
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
                $qb->expr()->eq($groupEntityAlias . '.' . $groupEntityColumn, $entityAlias . '.id')
            );
        }

        if (!empty($query['group'])) {
            $groups = $this->cleanStrings($query['group']);
            if (empty($groups)) {
                return;
            }
            $isId = preg_match('~^\d+$~', reset($groups));
            $qb = $event->getParam('queryBuilder');
            $adapter = $event->getTarget();
            $entityAlias = $adapter->getEntityClass();
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
                    ->andWhere($qb->expr()->in($groupEntityAlias . '.group', $groups));
            } else {
                $qb
                    ->innerJoin(
                        Group::class,
                        $groupAlias,
                        'WITH',
                        "$groupEntityAlias.group = $groupAlias.id"
                    )
                    ->andWhere($qb->expr()->in($groupAlias . '.name', $groups));
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
                        $qb->expr()->andX(
                            $qb->expr()->eq($groupEntityAlias . '.' . $groupEntityColumn, $entityAlias . '.id'),
                            $qb->expr()->eq($groupEntityAlias . '.group', $groupAlias . '.id')
                        )
                    );
                if ($isId) {
                    $qb
                        ->andWhere($qb->expr()->eq(
                            $groupAlias . '.id',
                            $adapter->createNamedParameter($qb, $group)
                        ));
                } else {
                    $qb
                        ->andWhere($qb->expr()->eq(
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
    public function handleCreatePost(Event $event)
    {
        $resourceAdapter = $event->getTarget();
        $resourceType = $resourceAdapter->getEntityClass();
        if (!$this->checkAcl($resourceType, 'create')) {
            return;
        }

        $response = $event->getParam('response');
        $request = $response->getRequest();
        $data = $request->getContent();
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
    public function handleUpdatePost(Event $event)
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
    public function handleBatchUpdatePost(Event $event)
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
    public function handleRecursiveDeleteItemSetPre(Event $event)
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
    public function addHeadersAdmin(Event $event)
    {
        $view = $event->getTarget();
        $view->headLink()->appendStylesheet($view->assetUrl('css/group.css', 'Group'));
        $view->headScript()->appendFile($view->assetUrl('js/group.js', 'Group'));
    }

    public function addUserFormElement(Event $event)
    {
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

    public function addUserFormFilter(Event $event)
    {
        if (!$this->checkAcl(User::class, 'update') || !$this->checkAcl(User::class, 'assign')) {
            return;
        }

        // TODO Add a validator for the groups of user.
        $inputFilter = $event->getParam('inputFilter');
        $inputFilter->get('user-information')->add([
            'name' => 'o-module-group:group',
            'required' => false,
        ]);
    }

    public function addUserFormValue(Event $event)
    {
        $user = $event->getTarget()->vars()->user;
        $form = $event->getParam('form');
        $values = $this->listGroups($user, 'reference');
        $form->get('user-information')->get('o-module-group:group')
            ->setAttribute('value', array_keys($values));
    }

    public function addBatchUpdateFormElement(Event $event)
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

    public function addBatchUpdateFormFilter(Event $event)
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

        $isUser = $resourceType === User::class;
        $groupEntityClass = $isUser ? GroupUser::class : GroupResource::class;

        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');
        if ($acl->userIsAllowed($groupEntityClass, 'delete')) {
            $inputFilter = $event->getParam('inputFilter');
            $inputFilter->add([
                'name' => 'remove_groups',
                'required' => false,
            ]);
        }
        if ($acl->userIsAllowed($groupEntityClass, 'create')) {
            $inputFilter = $event->getParam('inputFilter');
            $inputFilter->add([
                'name' => 'add_groups',
                'required' => false,
            ]);
        }
        // TODO Add a validator for the groups of resource.
    }

    /**
     * Add the tab to section navigation.
     *
     * @param Event $event
     */
    public function addTab(Event $event)
    {
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['groups'] = 'Groups'; // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    /**
     * Display the grouping form.
     *
     * @todo Merge with user groups form.
     *
     * @param Event $event
     */
    public function displayGroupResourceForm(Event $event)
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
     *
     * @param Event $event
     */
    public function viewShowAfterUser(Event $event)
    {
        $resource = $event->getTarget()->vars()->user;
        $this->displayViewAdmin($event, $resource, false);
    }

    /**
     * Display the groups for a resource.
     *
     * @param Event $event
     */
    public function viewShowAfterResource(Event $event)
    {
        echo '<div id="groups" class="section">';
        $resource = $event->getTarget()->vars()->resource;
        $this->displayViewAdmin($event, $resource, false);
        echo '</div>';
    }

    /**
     * Add details for a resource.
     *
     * @param Event $event
     */
    public function viewDetails(Event $event)
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
    ) {
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
     *
     * @param Event $event
     */
    public function displayAdvancedSearch(Event $event)
    {
        $services = $this->getServiceLocator();
        $formElementManager = $services->get('FormElementManager');
        $form = $formElementManager->get(SearchForm::class);
        $form->init();

        $view = $event->getTarget();
        $query = $event->getParam('query', []);
        $resourceType = $event->getParam('resourceType');

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
     *
     * @param Event $event
     */
    public function filterSearchFilters(Event $event)
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

    /**
     * Helper to get the column id of a representation.
     *
     * Note: Resource representation have method resourceName(), but site page
     * and user don't. Site page has no getControllerName().
     *
     * @param AbstractEntityRepresentation $representation
     * @return string
     */
    protected function columnNameOfRepresentation(AbstractEntityRepresentation $representation)
    {
        $entityColumnNames = [
            'item-set' => 'item_set_id',
            'item' => 'item_id',
            'media' => 'media_id',
            'user' => 'user_id',
        ];
        $entityColumnName = $entityColumnNames[$representation->getControllerName()];
        return $entityColumnName;
    }

    /**
     * Check rights to manage groups.
     *
     * @param string $resourceClass
     * @param string $privilege
     * @return bool
     */
    protected function checkAcl($resourceClass, $privilege)
    {
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $groupEntity = $resourceClass == User::class
            ? GroupUser::class
            : GroupResource::class;
        return $acl->userIsAllowed($groupEntity, $privilege);
    }

    /**
     * Check if groups are applied from above.
     *
     * @param string $resourceClass
     * @return bool
     */
    protected function takeGroupsFromAbove($resourceClass)
    {
        switch ($resourceClass) {
            case ItemSet::class:
                return false;
            case Item::class:
                return $groupSettings = $this->getServiceLocator()
                    ->get('Config')['group']['config']['group_recursive_item_sets'];
            case Media::class:
                return $groupSettings = $this->getServiceLocator()
                    ->get('Config')['group']['config']['group_recursive_items'];
            case User::class:
            default:
                return false;
        }
    }

    /**
     * Check if groups apply recursively for resources below.
     *
     * @param string $resourceClass
     * @return bool
     */
    protected function isRecursive($resourceClass)
    {
        switch ($resourceClass) {
            case ItemSet::class:
                return $this->getServiceLocator()
                    ->get('Config')['group']['config']['group_recursive_item_sets'];
            case Item::class:
                return $this->getServiceLocator()
                    ->get('Config')['group']['config']['group_recursive_items'];
            case Media::class:
            case User::class:
            default:
                return false;
        }
    }

    /**
     * Helper to return groups of an entity.
     *
     * @param AbstractEntityRepresentation $resource
     * @param string $contentType "json" (default), "representation" or "reference".
     * @return array
     */
    protected function listGroups(AbstractEntityRepresentation $resource = null, $contentType = null)
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
    protected function cleanStrings($strings)
    {
        if (!is_array($strings)) {
            $strings = explode(',', $strings);
        }
        return array_filter(array_map('trim', $strings));
    }
}
