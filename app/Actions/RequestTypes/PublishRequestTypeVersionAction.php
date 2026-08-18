<?php

namespace App\Actions\RequestTypes;

use App\Enums\RequestTypeVersionStatus;
use App\Models\RequestTypeVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

final class PublishRequestTypeVersionAction
{
    public function execute(RequestTypeVersion $version): RequestTypeVersion
    {
        return DB::transaction(function () use ($version): RequestTypeVersion {
            $lockedVersion = RequestTypeVersion::query()
                ->with('fields')
                ->lockForUpdate()
                ->findOrFail($version->id);

            if ($lockedVersion->status !== RequestTypeVersionStatus::Draft) {
                throw new DomainException('Only draft request type versions can be published.');
            }

            if ($lockedVersion->fields->isEmpty()) {
                throw new DomainException('A request type version must define at least one field before publication.');
            }

            $schema = [];

            foreach ($lockedVersion->fields as $field) {
                $schema[] = [
                    'key' => $field->key,
                    'label' => $field->label,
                    'type' => $field->type->value,
                    'position' => $field->position,
                    'is_required' => $field->is_required,
                    'configuration' => $field->configuration,
                ];
            }

            $lockedVersion->update([
                'status' => RequestTypeVersionStatus::Published,
                'schema_hash' => hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR)),
                'published_at' => now(),
            ]);

            return $lockedVersion->refresh();
        });
    }
}
