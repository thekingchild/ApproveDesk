<?php

namespace App\Actions\RequestTypes;

use App\DTOs\RequestFieldData;
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

    /** @param list<RequestFieldData> $fields */
    public function execute(?RequestType $requestType, string $name, ?string $description, array $fields, User $actor): RequestTypeVersion
    {
        return DB::transaction(function () use ($requestType, $name, $description, $fields, $actor): RequestTypeVersion {
            $requestType ??= RequestType::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'description' => $description,
            ]);
            // Editing never re-opens an archived type; only Restore does (RV-BUG-31).
            $requestType->update(['name' => $name, 'description' => $description]);

            $draft = $requestType->versions()->where('status', RequestTypeVersionStatus::Draft)->first();

            if ($draft === null) {
                $draft = $requestType->versions()->create([
                    'version' => ((int) $requestType->versions()->max('version')) + 1,
                    'status' => RequestTypeVersionStatus::Draft,
                    'created_by' => $actor->id,
                ]);
            }

            $draft->fields()->delete();

            foreach ($fields as $position => $field) {
                $draft->fields()->create([
                    'key' => Str::snake($field->key),
                    'label' => $field->label,
                    'type' => $field->type,
                    'position' => $position,
                    'is_required' => $field->isRequired,
                    'is_searchable' => $field->isSearchable,
                    'is_filterable' => $field->isFilterable,
                    'is_reportable' => $field->isReportable,
                    'is_sensitive' => $field->isSensitive,
                    'is_approver_summary' => $field->isApproverSummary,
                    'configuration' => $field->configuration,
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
