<?php
namespace Group\Mapping;

use CSVImport\Mapping\AbstractMapping;
use Omeka\Stdlib\Message;
use Zend\View\Renderer\PhpRenderer;

class GroupMapping extends AbstractMapping
{
    protected $label = 'Group'; // @translate
    protected $name = 'group-module';

    public function getSidebar(PhpRenderer $view)
    {
        return $view->partial('common/admin/group-sidebar');
    }

    public function processRow(array $row)
    {
        // Reset the data and the map between rows.
        $this->setHasErr(false);
        $data = [];
        $map = [];

        // First, pull in the global settings.
        // Set columns.
        if (isset($this->args['column-group'])) {
            $map['group'] = $this->args['column-group'];
            $data['o-module-group:group'] = [];
        }

        // Set default values.
        if (!empty($this->args['o-module-group:group'])) {
            $data['o-module-group:group'] = [];
            foreach ($this->args['o-module-group:group'] as $id) {
                $isId = preg_match('~^\d+$~', $id);
                $data['o-module-group:group'][] = $isId
                    ? ['o:id' => (int) $id]
                    : ['o:name' => $id];
            }
        }

        // Second, map the row.
        $multivalueMap = isset($this->args['column-multivalue']) ? $this->args['column-multivalue'] : [];
        // TODO Allow to bypass the default multivalue separator for users and resources.
        $multivalueSeparator = isset($this->args['multivalue_separator']) ? $this->args['multivalue_separator'] : '';
        foreach ($row as $index => $values) {
            if (empty($multivalueMap[$index])) {
                $values = [$values];
            } else {
                $values = explode($multivalueSeparator, $values);
                $values = array_map(function ($v) {
                    return trim($v, "\t\n\r   ");
                }, $values);
            }
            $values = array_filter($values, 'strlen');
            if (isset($map['group'][$index])) {
                foreach ($values as $value) {
                    $group = $this->findGroup($value);
                    if ($group) {
                        $data['o-module-group:group'][] = $group->id();
                    }
                }
            }
        }

        return $data;
    }

    protected function findGroup($identifier)
    {
        $isId = preg_match('~^\d+$~', $identifier);
        $response = $this->api->search('groups', [$isId ? 'id' : 'name' => $identifier]);
        $result = $response->getContent();
        if (empty($result)) {
            $this->logger->err(new Message('"%s" is not a valid group.', $identifier)); // @translate
            $this->setHasErr(true);
            return false;
        }
        return reset($result);
    }
}
