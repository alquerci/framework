<?php

namespace Illuminate\Database\Eloquent\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;
use stdClass;

/**
 * @author Alexandre Quercia <alexandre.quercia@medoucine.com>
 */
trait AutoJoinRelationships
{
    /**
     * Add a relationship exists condition to the query with join clauses.
     *
     * @param string   $relationName
     * @param Closure  $callback
     * @param Builder  $parentQuery
     * @param stdClass $parentAlias
     *
     * @return Builder
     */
    public function autoJoin(string $relationName, Closure $callback = null, Builder $parentQuery = null, stdClass $parentAlias = null)
    {
        if (false !== strpos($relationName, '.')) {
            return $this->autoJoinNested($relationName, $callback, $parentQuery);
        }

        // Ensure only root columns are selected.
        $this->select([$this->qualifyColumn('*')]);

        // Avoid root record duplication.
        $this->distinct();

        $parentQuery = $parentQuery ?? $this;
        $parentModel = $parentQuery->getModel();

        // Get the "has relation" base query instance.
        $relation = Relation::noConstraints(function () use ($relationName, $parentModel) {
            return $parentModel->{$relationName}();
        });

        // Updates parent alias table.
        if (null !== ($parentAlias->value ?? null)) {
            $relation->getParent()->setTable($parentAlias->value);
        }

        // Creates the query builder for join "on" condition.
        $hasQuery = $relation->getRelationExistenceQuery(
            $relation->getRelated()->newQuery(), $parentQuery
        );

        // Set the alias of the relation that becomes the parent alias for child.
        if (null !== $parentAlias) {
            $parentAlias->value = $relation->getRelated()->getTable();
        }

        // Merges relationship constraints as join.
        $this->mergeJoins($hasQuery->getQuery()->joins, $hasQuery->getBindings());

        // Next we will call any given callback as an "anonymous" scope so they can get the
        // proper logical grouping of the where clauses if needed by this Eloquent query
        // builder. Then, we will be ready to finalize and return this query instance.
        if (null !== $callback) {
            $query = $hasQuery->getQuery();

            $originalJoinCount = count($query->joins ?? []);
            $originalHavingCount = count($query->havings ?? []);

            $hasQuery->callScope($callback);

            if ($originalJoinCount < count($query->joins ?? [])) {
                throw new LogicException('Unsupported autoJoin nested on constraint as callback.');
            }

            if ($originalHavingCount < count($query->havings ?? [])) {
                throw new LogicException('Unsupported having constraint on autoJoin constraint as callback.');
            }
        }

        // Merge the where constraints from relationship query definition
        // to the constraint query.
        $hasQuery->mergeConstraintsFrom($relation->getQuery());

        // Add the "has" condition where clause to the query.
        return $this->join(
            $hasQuery->getQuery()->from,
            function ($join) use ($hasQuery) {
                $join->addNestedWhereQuery($hasQuery->toBase());
            }
        );
    }

    private function autoJoinNested(string $relationName, Closure $callback = null, Builder $parentQuery = null)
    {
        $relationNames = explode('.', $relationName);
        $parentQuery = $parentQuery ?? $this;
        $parentAlias = new stdClass();
        $applyCallback = null;

        do {
            $relationName = array_shift($relationNames);

            // Apply the callback only for the last relation.
            if (!$relationNames) {
                $applyCallback = $callback;
            }

            $this->autoJoin($relationName, $applyCallback, $parentQuery, $parentAlias);

            $parentTable = $parentQuery->getModel()->getTable();

            $relation = Relation::noConstraints(function () use ($relationName, $parentQuery) {
                return $parentQuery->getModel()->{$relationName}();
            });

            $parentQuery = $relation->getModel()->newModelQuery();

            if ($parentTable === $relation->getModel()->getTable()) {
                throw new LogicException('Unsupported autoJoin on nested self referencing.');
            }
        } while ($relationNames);

        return $this;
    }

    private function mergeJoins($joins, $bindings)
    {
        $query = $this->getQuery();

        $query->joins = array_merge((array) $query->joins, (array) $joins);

        $query->bindings['join'] = array_values(
            array_merge($query->bindings['join'], (array) $bindings)
        );
    }
}
