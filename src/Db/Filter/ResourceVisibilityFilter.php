<?php
namespace Group\Db\Filter;

use Doctrine\DBAL\Types\Type;

/**
 * Filter resources by default rules and by groups too.
 *
 * Users can view private resources when they have at least one group in common.
 *
 * {@inheritdoc}
 *
 * Note: It's possible to add a constraint to restrict access via the trigger or
 * the main config, but not to extend rights, so the default filter is extended
 * directly.
 */
class ResourceVisibilityFilter extends \Omeka\Db\Filter\ResourceVisibilityFilter
{
    protected function getResourceConstraint($alias)
    {
        $constraints = parent::getResourceConstraint($alias);

        // Don't add a constraint for admins or visitors, who already view all
        // or nothing private.
        if (empty($constraints)) {
            return $constraints;
        }

        $identity = $this->serviceLocator->get('Omeka\AuthenticationService')->getIdentity();

        // Users can view private resources when they have at least one group in
        // common.
        if ($identity) {
            // Because the groups are assigned recursively, by default, a simple
            // check of the groups of the users and the groups of the resource
            // allows to determine the rights.
            // TODO Use a named query.
            // TODO Add a join the resource type to improve the sub query (which alias? which id?)
            // INNER JOIN item ON group_resource.resource_id = item.id LIMIT 1
            $constraints .= sprintf(
                ' OR %s.id IN (
SELECT group_resource.resource_id
FROM group_resource
INNER JOIN group_user ON group_resource.group_id = group_user.group_id AND group_user.user_id = %s
)',
                $alias,
                $this->getConnection()->quote($identity->getId(), Type::INTEGER));
        }

        return $constraints;
    }
}
