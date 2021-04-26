<?php declare(strict_types=1);

namespace Group\Service\ViewHelper;

use Group\View\Helper\GroupCount;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class GroupCountFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new GroupCount(
            $services->get('Omeka\Connection')
        );
    }
}
