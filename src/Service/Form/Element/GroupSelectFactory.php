<?php declare(strict_types=1);

namespace Group\Service\Form\Element;

use Group\Form\Element\GroupSelect;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class GroupSelectFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $element = new GroupSelect(null, $options ?? []);
        return $element
            ->setApiManager($services->get('Omeka\ApiManager'))
            ->setUrlHelper($services->get('ViewHelperManager')->get('Url'));
    }
}
