<?php declare(strict_types=1);

namespace Group\Service\ControllerPlugin;

use Group\Mvc\Controller\Plugin\ListGroups;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ListGroupsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new ListGroups(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Acl')
        );
    }
}
