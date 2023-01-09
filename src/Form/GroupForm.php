<?php declare(strict_types=1);

namespace Group\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class GroupForm extends Form
{
    public function init(): void
    {
        $this
            ->setAttribute('id', 'group-form')
            ->add([
                'name' => 'o:name',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Name', // @translate
                ],
                'attributes' => [
                    'id' => 'name',
                    'required' => true,
                ],
            ])
            ->add([
                'name' => 'o:comment',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Comment', // @translate
                ],
                'attributes' => [
                    'id' => 'comment',
                ],
            ]);
    }
}
