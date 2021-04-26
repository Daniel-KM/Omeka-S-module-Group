<?php declare(strict_types=1);

namespace Group\View\Helper;

use Laminas\View\Helper\AbstractHelper;

class GroupSelector extends AbstractHelper
{
    /**
     * Return the group selector form control.
     */
    public function __invoke(): string
    {
        $view = $this->getView();
        $response = $view->api()->search('groups', ['sort_by' => 'name']);
        $groups = $response->getContent();
        return $view->partial(
            'common/admin/groups-selector',
            [
                'groups' => $groups,
                'totalGroupCount' => $response->getTotalResults(),
            ]
        );
    }
}
