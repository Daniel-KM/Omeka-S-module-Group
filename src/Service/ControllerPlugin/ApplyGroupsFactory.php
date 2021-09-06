<?php declare(strict_types=1);

namespace Group\Service\ControllerPlugin;

use Group\Mvc\Controller\Plugin\ApplyGroups;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ApplyGroupsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new ApplyGroups(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Acl'),
            $services->get('Omeka\EntityManager')
        );
    }
}
