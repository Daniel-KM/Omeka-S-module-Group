<?php
namespace Group\Service\ControllerPlugin;

use Group\Mvc\Controller\Plugin\ApplyGroups;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class ApplyGroupsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedNamed, array $options = null)
    {
        $api = $services->get('Omeka\ApiManager');
        $acl = $services->get('Omeka\Acl');
        $entityManager = $services->get('Omeka\EntityManager');
        return new ApplyGroups(
            $api,
            $acl,
            $entityManager
        );
    }
}
