<?php
namespace Group\Service\ViewHelper;

use Group\View\Helper\GroupCount;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class GroupCountFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $connection = $services->get('Omeka\Connection');
        return new GroupCount($connection);
    }
}
