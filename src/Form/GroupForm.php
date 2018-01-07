<?php
namespace Group\Form;

use Zend\Form\Form;

class GroupForm extends Form
{
    public function init()
    {
        $this->setAttribute('id', 'group-form');

        $this->add([
            'name' => 'o:name',
            'type' => 'Text',
            'options' => [
                'label' => 'Name', // @translate
            ],
            'attributes' => [
                'id' => 'name',
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => 'o:comment',
            'type' => 'Text',
            'options' => [
                'label' => 'Comment', // @translate
            ],
            'attributes' => [
                'id' => 'comment',
                'required' => false,
            ],
        ]);
    }
}
