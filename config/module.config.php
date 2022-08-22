<?php declare(strict_types=1);

/**
 * @todo Replace the two tables "group_user" and "group_resource" by one
 * "grouping" with one column "entity_type": it will simplify a lot of
 * thing, but will this improve performance (search with a ternary key)?
 * This will be checked if a new group of something is needed (for sites).
 */
namespace Group;

return [
    'permissions' => [
        'acl_resources' => [
            Entity\GroupResource::class,
            Entity\GroupUser::class,
        ],
    ],
    'api_adapters' => [
        'invokables' => [
            'groups' => Api\Adapter\GroupAdapter::class,
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
        'filters' => [
            'resource_visibility' => Db\Filter\ResourceVisibilityFilter::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'csvimport' => [
        'mappings' => [
            'item_sets' => [
                'mappings' => [
                    Mapping\GroupMapping::class,
                ],
            ],
            'items' => [
                'mappings' => [
                    Mapping\GroupMapping::class,
                ],
            ],
            'media' => [
                'mappings' => [
                    Mapping\GroupMapping::class,
                ],
            ],
            'resources' => [
                'mappings' => [
                    Mapping\GroupMapping::class,
                ],
            ],
            'users' => [
                'mappings' => [
                    Mapping\GroupMapping::class,
                ],
            ],
        ],
        'automapping' => [
            'group' => [
                'name' => 'group',
                'value' => 1,
                'label' => 'Group',
                'class' => 'group-module',
            ],
        ],
        'user_settings' => [
            'csvimport_automap_user_list' => [
                'group' => 'group',
            ],
        ],
    ],
    'view_helpers' => [
        'invokables' => [
            'groupSelector' => View\Helper\GroupSelector::class,
        ],
        'factories' => [
            'groupCount' => Service\ViewHelper\GroupCountFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\GroupForm::class => Form\GroupForm::class,
            Form\SearchForm::class => Form\SearchForm::class,
        ],
        'factories' => [
            Form\Element\GroupSelect::class => Service\Form\Element\GroupSelectFactory::class,
        ],
    ],
    'navigation' => [
        'AdminGlobal' => [
            [
                'label' => 'Groups', // @translate
                'class' => 'o-icon-users',
                'route' => 'admin/group',
                'resource' => Controller\Admin\GroupController::class,
                'privilege' => 'browse',
            ],
        ],
    ],
    'controllers' => [
        'invokables' => [
            Controller\Admin\GroupController::class => Controller\Admin\GroupController::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'applyGroups' => Service\ControllerPlugin\ApplyGroupsFactory::class,
            'listGroups' => Service\ControllerPlugin\ListGroupsFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'group' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/group[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'Group\Controller\Admin',
                                'controller' => Controller\Admin\GroupController::class,
                                'action' => 'browse',
                            ],
                        ],
                    ],
                    'group-id' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/group/:id[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'Group\Controller\Admin',
                                'controller' => Controller\Admin\GroupController::class,
                                'action' => 'show',
                            ],
                        ],
                    ],
                    'group-name' => [
                        'type' => 'Segment',
                        'options' => [
                            // The action is required to avoid collision with admin/group.
                            // A validation is done in the adapter.
                            'route' => '/group/:name/:action',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'name' => '[^\d]+.*',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'Group\Controller\Admin',
                                'controller' => Controller\Admin\GroupController::class,
                                'action' => 'show',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'js_translate_strings' => [
        'Request too long to process.', // @translate
    ],
    // Don't edit these options here: copy this key in your own omeka config/local.config.php
    // and modify options as you want.
    'group' => [
        'config' => [
            // Apply the groups of item sets to items and medias.
            'group_recursive_item_sets' => true,
            // Apply the item groups to medias. Implied and not taken in account
            // when `group_recursive_item_sets` is true.
            'group_recursive_items' => true,
        ],
    ],
];
