<?php
namespace Group\Form;

use Zend\Form\Element\Checkbox;
use Zend\Form\Element\Text;
use Zend\Form\Form;

class SearchForm extends Form
{
    public function init()
    {
        $this->add([
            'type' => Checkbox::class,
            'name' => 'has_groups',
            'options' => [
                'label' => 'Has groups', // @translate
            ],
        ]);

        $this->add([
            'type' => Text::class,
            'name' => 'group',
            'options' => [
                'label' => 'Search by group', // @translate
                'info' => 'Multiple groups may be comma-separated.', // @translate
            ],
        ]);
    }
}
