<?php
namespace Group\View\Helper;

use Zend\View\Helper\AbstractHelper;

class GroupSelector extends AbstractHelper
{
    /**
     * Return the group selector form control.
     *
     * @return string
     */
    public function __invoke()
    {
        $response = $this->getView()->api()->search('groups', ['sort_by' => 'name']);
        $groups = $response->getContent();
        return $this->getView()->partial(
            'common/admin/groups-selector',
            [
                'groups' => $groups,
                'totalGroupCount' => $response->getTotalResults(),
            ]
        );
    }
}
