<?php declare(strict_types=1);
namespace Group\View\Helper;

use Laminas\View\Helper\AbstractHelper;

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
