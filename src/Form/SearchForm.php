<?php declare(strict_types=1);
namespace Group\Form;

use Laminas\Form\Element\Checkbox;
use Laminas\Form\Element\Text;
use Laminas\Form\Form;

class SearchForm extends Form
{
    public function init(): void
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
