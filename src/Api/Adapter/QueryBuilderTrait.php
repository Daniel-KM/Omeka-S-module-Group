<?php declare(strict_types=1);
namespace Group\Api\Adapter;

use Doctrine\ORM\QueryBuilder;

/**
 * Trait to build queries.
 */
trait QueryBuilderTrait
{
    /**
     * Helper to search one or multiple values.
     *
     * @param QueryBuilder $qb
     * @param mixed $values One or multiple values.
     * @param string $column
     * @param string $target
     */
    protected function buildQueryValues(QueryBuilder $qb, $values, $column, $target): void
    {
        if (is_array($values)) {
            if (count($values) == 1) {
                $this->buildQueryOneValue($qb, reset($values), $column);
            } else {
                $this->buildQueryMultipleValues($qb, $values, $column, $target);
            }
        } else {
            $this->buildQueryOneValue($qb, $values, $column);
        }
    }

    /**
     * Helper to search one value.
     *
     * @param QueryBuilder $qb
     * @param mixed $value
     * @param string $column
     */
    protected function buildQueryOneValue(QueryBuilder $qb, $value, $column): void
    {
        $expr = $qb->expr();

        if (is_null($value)) {
            $qb->andWhere($expr->isNull(
                'omeka_root.' . $column
            ));
        } else {
            $qb->andWhere($expr->eq(
                'omeka_root.' . $column,
                $this->createNamedParameter($qb, $value)
            ));
        }
    }

    /**
     * Helper to search multiple values ("OR").
     *
     * @param QueryBuilder $qb
     * @param array $values
     * @param string $column
     * @param string $target
     */
    protected function buildQueryMultipleValues(QueryBuilder $qb, array $values, $column, $target): void
    {
        $hasNull = in_array(null, $values, true);
        $values = array_filter($values, function ($v) {
            return !is_null($v);
        });
        if ($values) {
            $expr = $qb->expr();

            $valueAlias = $this->createAlias();
            $qb->innerJoin(
                'omeka_root.' . $column,
                $valueAlias,
                'WITH',
                $hasNull
                    ? $expr->orX(
                        $expr->in(
                            $valueAlias . '.' . $target,
                            $this->createNamedParameter($qb, $values)
                        ),
                        $expr->isNull(
                            $valueAlias . '.' . $target
                        )
                    )
                    : $expr->in(
                        $valueAlias . '.' . $target,
                        $this->createNamedParameter($qb, $values)
                    )
                );
        }
        // Check no value only.
        elseif ($hasNull) {
            $expr = $qb->expr();
            $qb->andWhere($expr->isNull(
                'omeka_root.' . $column
            ));
        }
    }

    /**
     * Helper to search one or multiple ids.
     *
     * There is no "0" for id, but "null" may be allowed.
     *
     * @param QueryBuilder $qb
     * @param mixed $values One or multiple ids.
     * @param string $column
     * @param string $target
     */
    protected function buildQueryIds(QueryBuilder $qb, $values, $column, $target = 'id'): void
    {
        if (is_array($values)) {
            if (count($values) == 1) {
                $this->buildQueryOneId($qb, reset($values), $column);
            } else {
                $this->buildQueryMultipleIds($qb, $values, $column, $target);
            }
        } else {
            $this->buildQueryOneId($qb, $values, $column);
        }
    }

    /**
     * Helper to search one id.
     *
     * There is no "0" for id, but "null" may be allowed.
     *
     * @param QueryBuilder $qb
     * @param mixed $value
     * @param string $column
     */
    protected function buildQueryOneId(QueryBuilder $qb, $value, $column): void
    {
        $value = ($value && is_numeric($value)) ? $value : null;
        $this->buildQueryOneValue($qb, $value, $column);
    }

    /**
     * Helper to search multiple ids.
     *
     * There is no "0" for id, but "null" may be allowed.
     *
     * @param QueryBuilder $qb
     * @param array $values Multiple ids.
     * @param string $column
     * @param string $target
     */
    protected function buildQueryMultipleIds(QueryBuilder $qb, $values, $column, $target = 'id'): void
    {
        $hasEmpty = in_array(null, $values);
        $values = array_filter($values, 'is_numeric');
        if ($hasEmpty) {
            $values[] = null;
        }
        $this->buildQueryMultipleValues($qb, $values, $column, $target);
    }

    /**
     * Helper to search one or multiple values on the same entity.
     *
     * @param QueryBuilder $qb
     * @param mixed $values One or multiple values.
     * @param string $target
     */
    protected function buildQueryValuesItself(QueryBuilder $qb, $values, $target): void
    {
        if (is_array($values)) {
            if (count($values) == 1) {
                $this->buildQueryOneValue($qb, reset($values), $target);
            } else {
                $this->buildQueryMultipleValuesItself($qb, $values, $target);
            }
        } else {
            $this->buildQueryOneValue($qb, $values, $target);
        }
    }

    /**
     * Helper to search multiple values ("OR") on the same entity.
     *
     * @param QueryBuilder $qb
     * @param array $values
     * @param string $target
     */
    protected function buildQueryMultipleValuesItself(QueryBuilder $qb, array $values, $target): void
    {
        $hasNull = in_array(null, $values, true);
        $values = array_filter($values, function ($v) {
            return !is_null($v);
        });
        if ($values) {
            $expr = $qb->expr();

            $valueAlias = $this->createAlias();
            $qb
                ->innerJoin(
                    $this->getEntityClass(),
                    $valueAlias,
                    'WITH',
                    $expr->eq(
                        'omeka_root.id',
                        $valueAlias . '.id'
                    )
                )
                ->andWhere(
                    $hasNull
                    ? $expr->orX(
                        $expr->in(
                            $valueAlias . '.' . $target,
                            $this->createNamedParameter($qb, $values)
                        ),
                        $expr->isNull(
                            $valueAlias . '.' . $target
                        )
                    )
                    : $expr->in(
                        $valueAlias . '.' . $target,
                        $this->createNamedParameter($qb, $values)
                    )
                );
        }
        // Check no value only.
        elseif ($hasNull) {
            $expr = $qb->expr();
            $qb->andWhere($expr->isNull(
                'omeka_root.' . $target
            ));
        }
    }

    /**
     * Helper to search one or multiple ids on the same entity.
     *
     * There is no "0" for id, but "null" may be allowed.
     *
     * @param QueryBuilder $qb
     * @param mixed $values One or multiple ids.
     * @param string $target
     */
    protected function buildQueryIdsItself(QueryBuilder $qb, $values, $target = 'id'): void
    {
        if (is_array($values)) {
            if (count($values) == 1) {
                $this->buildQueryOneId($qb, reset($values), $target);
            } else {
                $this->buildQueryMultipleIdsItself($qb, $values, $target);
            }
        } else {
            $this->buildQueryOneId($qb, $values, $target);
        }
    }

    /**
     * Helper to search multiple ids on the same entity.
     *
     * There is no "0" for id, but "null" may be allowed.
     *
     * @param QueryBuilder $qb
     * @param array $values Multiple ids.
     * @param string $target
     */
    protected function buildQueryMultipleIdsItself(QueryBuilder $qb, $values, $target = 'id'): void
    {
        $hasEmpty = in_array(null, $values);
        $values = array_filter($values, 'is_numeric');
        if ($hasEmpty) {
            $values[] = null;
        }
        $this->buildQueryMultipleValuesItself($qb, $values, $target);
    }
}
