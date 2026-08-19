<?php

namespace App\Actions\RequestTypes;

use App\Enums\RequestFieldType;
use App\Enums\RequestTypeVersionStatus;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\User;
use App\Services\RequestTypes\RequestSchemaCompiler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SaveRequestTypeDraftAction
{
    public function __construct(private readonly RequestSchemaCompiler $compiler) {}

    /** @param array<int, array<string, mixed>> $fields */
    public function execute(?RequestType $requestType, string $name, ?string $description, array $fields, User $actor): RequestTypeVersion
    {
        return DB::transaction(function () use ($requestType, $name, $description, $fields, $actor): RequestTypeVersion {
            $requestType ??= RequestType::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'description' => $description,
            ]);
            $requestType->update(['name' => $name, 'description' => $description, 'is_active' => true]);

            $draft = $requestType->versions()->where('status', RequestTypeVersionStatus::Draft)->first();

            if ($draft === null) {
                $draft = $requestType->versions()->create([
                    'version' => ((int) $requestType->versions()->max('version')) + 1,
                    'status' => RequestTypeVersionStatus::Draft,
                    'created_by' => $actor->id,
                ]);
            }

            $draft->fields()->delete();

            foreach (array_values($fields) as $position => $field) {
                $draft->fields()->create([
                    'key' => Str::snake((string) $field['key']),
                    'label' => $field['label'],
                    'type' => RequestFieldType::from($field['type']),
                    'position' => $position,
                    'is_required' => (bool) ($field['is_required'] ?? false),
                    'is_searchable' => (bool) ($field['is_searchable'] ?? false),
                    'is_filterable' => (bool) ($field['is_filterable'] ?? false),
                    'is_reportable' => (bool) ($field['is_reportable'] ?? false),
                    'is_sensitive' => (bool) ($field['is_sensitive'] ?? false),
                    'is_approver_summary' => (bool) ($field['is_approver_summary'] ?? false),
                    'configuration' => $field['configuration'] ?? null,
                ]);
            }

            $draft->load('fields');
            $this->compiler->assertSchemaValid($draft->fields);

            return $draft;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'request-type';
        $slug = $base;
        $suffix = 2;

        while (RequestType::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
