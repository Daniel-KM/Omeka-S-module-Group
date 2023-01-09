<?php declare(strict_types=1);

namespace Group\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class SearchForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'has_groups',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Has groups', // @translate
                ],
            ])
            ->add([
                'name' => 'group',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Search by group', // @translate
                    'info' => 'Multiple groups may be comma-separated.', // @translate
                ],
            ]);
    }
}
