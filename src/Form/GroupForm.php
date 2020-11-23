<?php declare(strict_types=1);
namespace Group\Form;

use Laminas\Form\Form;

class GroupForm extends Form
{
    public function init(): void
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
