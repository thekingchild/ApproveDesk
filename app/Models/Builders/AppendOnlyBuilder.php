<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Eloquent builder for append-only tables. Model events already stop
 * $model->update()/delete(); this also stops bulk query-builder writes such
 * as WorkflowAction::query()->update([...]) (RV-ENG-07).
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class AppendOnlyBuilder extends Builder
{
    public function update(array $values): int
    {
        $this->refuse('update');
    }

    public function delete(): mixed
    {
        $this->refuse('delete');
    }

    public function forceDelete(): mixed
    {
        $this->refuse('delete');
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int|string, mixed>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        $this->refuse('upsert');
    }

    public function increment($column, $amount = 1, array $extra = []): int
    {
        $this->refuse('update');
    }

    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $this->refuse('update');
    }

    private function refuse(string $operation): never
    {
        throw new LogicException($this->getModel()->getTable()." is append-only; bulk {$operation} is forbidden.");
    }
}
