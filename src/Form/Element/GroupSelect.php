<?php declare(strict_types=1);

namespace Group\Form\Element;

use Laminas\Form\Element\Select;
use Laminas\View\Helper\Url;
use Omeka\Api\Manager as ApiManager;

class GroupSelect extends Select
{
    /**
     * @var ApiManager
     */
    protected $apiManager;

    /**
     * @var Url
     */
    protected $url;

    /**
     * @see https://github.com/zendframework/zendframework/issues/2761#issuecomment-14488216
     *
     * {@inheritDoc}
     * @see \Laminas\Form\Element\Select::getInputSpecification()
     */
    public function getInputSpecification(): array
    {
        $inputSpecification = parent::getInputSpecification();
        $inputSpecification['required'] = !empty($this->attributes['required']);
        return $inputSpecification;
    }

    public function getValueOptions(): array
    {
        $query = $this->getOption('query');
        if (!is_array($query)) {
            $query = [];
        }
        if (!isset($query['sort_by'])) {
            $query['sort_by'] = 'name';
        }

        $nameAsValue = $this->getOption('name_as_value', false);

        $valueOptions = [];
        $response = $this->getApiManager()->search('groups', $query);
        foreach ($response->getContent() as $representation) {
            $name = $representation->name();
            $key = $nameAsValue ? $name : $representation->id();
            $valueOptions[$key] = $name;
        }

        $prependValueOptions = $this->getOption('prepend_value_options');
        if (is_array($prependValueOptions)) {
            $valueOptions = $prependValueOptions + $valueOptions;
        }
        return $valueOptions;
    }

    public function setOptions($options)
    {
        if (!empty($options['chosen'])) {
            $defaultOptions = [
                'resource_value_options' => [
                    'resource' => 'groups',
                    'query' => [],
                    'option_text_callback' => function ($v) {
                        return $v->name();
                    },
                ],
                'name_as_value' => true,
            ];
            if (isset($options['resource_value_options'])) {
                $options['resource_value_options'] += $defaultOptions['resource_value_options'];
            } else {
                $options['resource_value_options'] = $defaultOptions['resource_value_options'];
            }
            if (!isset($options['name_as_value'])) {
                $options['name_as_value'] = $defaultOptions['name_as_value'];
            }

            $urlHelper = $this->getUrlHelper();
            $defaultAttributes = [
                'class' => 'chosen-select',
                'data-placeholder' => 'Select groups…', // @translate
                'data-api-base-url' => $urlHelper('api/default', ['resource' => 'groups']),
            ];
            $this->setAttributes($defaultAttributes);
        }

        return parent::setOptions($options);
    }

    public function setApiManager(ApiManager $apiManager): self
    {
        $this->apiManager = $apiManager;
        return $this;
    }

    public function getApiManager(): ApiManager
    {
        return $this->apiManager;
    }

    public function setUrlHelper(Url $urlHelper): self
    {
        $this->urlHelper = $urlHelper;
        return $this;
    }

    public function getUrlHelper(): Url
    {
        return $this->urlHelper;
    }
}
