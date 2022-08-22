<?php declare(strict_types=1);

namespace Group\Mapping;

use CSVImport\Mapping\AbstractMapping;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Stdlib\Message;

class GroupMapping extends AbstractMapping
{
    protected $label = 'Group'; // @translate
    protected $name = 'group-module';

    public function getSidebar(PhpRenderer $view)
    {
        return $view->partial('common/admin/group-mapping-sidebar');
    }

    public function processRow(array $row)
    {
        // Reset the data and the map between rows.
        $this->setHasErr(false);
        $this->data = [];
        $this->map = [];

        // First, pull in the global settings.
        $this->processGlobalArgs();

        // TODO Allow to bypass the default multivalue separator for users and resources.
        $multivalueMap = $this->args['column-multivalue'] ?? [];
        foreach ($row as $index => $values) {
            if (array_key_exists($index, $multivalueMap) && strlen($multivalueMap[$index])) {
                $values = explode($multivalueMap[$index], $values);
                $values = array_map(function ($v) {
                    return trim($v, "\t\n\r \u{a0}\u{202f}");
                }, $values);
            } else {
                $values = [$values];
            }
            $values = array_filter($values, 'strlen');
            if ($values) {
                $this->processCell($index, $values);
            }
        }

        return $this->data;
    }

    protected function processGlobalArgs(): void
    {
        $data = &$this->data;

        // Set columns.
        if (isset($this->args['column-group'])) {
            $this->map['group'] = $this->args['column-group'];
            $data['o-module-group:group'] = [];
        }

        // Set default values.
        if (!empty($this->args['o-module-group:group'])) {
            $data['o-module-group:group'] = [];
            $args = is_array($this->args['o-module-group:group'])
                ? $this->args['o-module-group:group']
                // TODO Explode global groups.
                : [$this->args['o-module-group:group']];
            foreach ($args as $id) {
                $isId = preg_match('~^\d+$~', $id);
                $data['o-module-group:group'][] = $isId
                    ? ['o:id' => (int) $id]
                    : ['o:name' => $id];
            }
        }
    }

    protected function processCell($index, array $values): void
    {
        $data = &$this->data;

        if (isset($this->map['group'][$index])) {
            foreach ($values as $value) {
                $group = $this->findGroup($value);
                if ($group && !in_array($group->id(), $data['o-module-group:group'])) {
                    $data['o-module-group:group'][] = $group->id();
                }
            }
        }
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
