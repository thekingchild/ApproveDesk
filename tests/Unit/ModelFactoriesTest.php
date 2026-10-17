<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** RV-LAR-12: every model has a factory that builds a complete, valid row. */
class ModelFactoriesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class-string<Model>}> */
    public static function models(): array
    {
        $models = [];
        foreach (glob(__DIR__.'/../../app/Models/*.php') ?: [] as $path) {
            $class = 'App\\Models\\'.basename($path, '.php');
            if (is_subclass_of($class, Model::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $models[class_basename($class)] = [$class];
            }
        }

        return $models;
    }

    /** @param class-string<Model> $class */
    #[DataProvider('models')]
    public function test_the_model_has_a_factory_that_creates_a_complete_row(string $class): void
    {
        $this->assertContains(HasFactory::class, class_uses_recursive($class), "{$class} has no factory.");

        // Singletons (the System owner, the site limit) may already have their one row.
        if (defined($class.'::SINGLETON_ID')) {
            $class::query()->whereKey(constant($class.'::SINGLETON_ID'))->delete();
        }

        /** @var Model $model */
        $model = $class::factory()->create();

        $this->assertTrue($model->exists);
        // Strict mode (RV-LAR-03) throws on any column the factory left unset.
        foreach (Schema::getColumnListing($model->getTable()) as $column) {
            $model->getAttribute($column);
        }
    }
}
