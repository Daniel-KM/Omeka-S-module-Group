<?php declare(strict_types=1);

namespace Group\Api\Representation;

use Omeka\Api\Adapter\AdapterInterface;
use Omeka\Api\Representation\ResourceReference;
use Omeka\Api\ResourceInterface;

class GroupReference extends ResourceReference
{
    /**
     * @var string
     */
    protected $name;

    public function __construct(ResourceInterface $resource, AdapterInterface $adapter)
    {
        $this->name = $resource->getName();
        parent::__construct($resource, $adapter);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function jsonSerialize()
    {
        return [
            '@id' => $this->apiUrl(),
            'o:id' => $this->id(),
            'o:name' => $this->name(),
        ];
    }
}
