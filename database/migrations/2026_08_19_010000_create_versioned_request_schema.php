<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('request_type_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->string('schema_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['request_type_id', 'version']);
            $table->index(['request_type_id', 'status']);
        });

        Schema::create('request_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_type_version_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('label', 150);
            $table->string('type', 30);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_required')->default(false);
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->unique(['request_type_version_id', 'key']);
            $table->index(['request_type_version_id', 'position']);
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->foreignId('request_type_version_id')
                ->nullable()
                ->after('type')
                ->constrained()
                ->restrictOnDelete();
        });

        Schema::create('submission_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('request_field_definition_id')->constrained()->restrictOnDelete();
            $table->string('string_value', 500)->nullable();
            $table->longText('text_value')->nullable();
            $table->decimal('decimal_value', 20, 6)->nullable();
            $table->date('date_value')->nullable();
            $table->dateTime('datetime_value')->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->foreignId('user_value_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_value_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->json('json_value')->nullable();
            $table->timestamps();

            $table->unique(['submission_id', 'request_field_definition_id']);
            $table->index(['request_field_definition_id', 'string_value'], 'submission_field_string_index');
            $table->index(['request_field_definition_id', 'decimal_value'], 'submission_field_decimal_index');
            $table->index(['request_field_definition_id', 'date_value'], 'submission_field_date_index');
            $table->index(['request_field_definition_id', 'datetime_value'], 'submission_field_datetime_index');
            $table->index(['request_field_definition_id', 'boolean_value'], 'submission_field_boolean_index');
            $table->index(['request_field_definition_id', 'user_value_id'], 'submission_field_user_index');
            $table->index(['request_field_definition_id', 'department_value_id'], 'submission_field_department_index');
        });

        $this->createGeneralRequestTypeAndBackfill();

        Schema::table('submissions', function (Blueprint $table) {
            $table->foreignId('request_type_version_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_field_values');

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('request_type_version_id');
        });

        Schema::dropIfExists('request_field_definitions');
        Schema::dropIfExists('request_type_versions');
        Schema::dropIfExists('request_types');
    }

    private function createGeneralRequestTypeAndBackfill(): void
    {
        $now = now();
        $fields = [
            ['key' => 'type', 'label' => 'Request category', 'type' => 'short_text', 'position' => 0, 'is_required' => true],
            ['key' => 'title', 'label' => 'Title', 'type' => 'short_text', 'position' => 1, 'is_required' => true],
            ['key' => 'body', 'label' => 'Body', 'type' => 'rich_text', 'position' => 2, 'is_required' => false],
            ['key' => 'priority', 'label' => 'Priority', 'type' => 'short_text', 'position' => 3, 'is_required' => true],
            ['key' => 'department', 'label' => 'Department', 'type' => 'department', 'position' => 4, 'is_required' => false],
            ['key' => 'due_date', 'label' => 'Due date', 'type' => 'date', 'position' => 5, 'is_required' => false],
        ];

        $requestTypeId = DB::table('request_types')->insertGetId([
            'name' => 'General Request',
            'slug' => 'general-request',
            'description' => 'Built-in request type for submissions created before configurable forms.',
            'is_active' => true,
            'is_system' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $versionId = DB::table('request_type_versions')->insertGetId([
            'request_type_id' => $requestTypeId,
            'version' => 1,
            'status' => 'published',
            'schema_hash' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)),
            'created_by' => null,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $definitionIds = [];

        foreach ($fields as $field) {
            $definitionIds[$field['key']] = DB::table('request_field_definitions')->insertGetId([
                ...$field,
                'request_type_version_id' => $versionId,
                'configuration' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('submissions')->orderBy('id')->chunkById(100, function ($submissions) use ($versionId, $definitionIds, $now): void {
            foreach ($submissions as $submission) {
                DB::table('submissions')->where('id', $submission->id)->update([
                    'request_type_version_id' => $versionId,
                ]);

                $values = [
                    ['request_field_definition_id' => $definitionIds['type'], 'string_value' => $submission->type],
                    ['request_field_definition_id' => $definitionIds['title'], 'string_value' => $submission->title],
                    ['request_field_definition_id' => $definitionIds['body'], 'text_value' => $submission->body],
                    ['request_field_definition_id' => $definitionIds['priority'], 'string_value' => $submission->priority],
                    ['request_field_definition_id' => $definitionIds['department'], 'department_value_id' => $submission->department_id],
                    ['request_field_definition_id' => $definitionIds['due_date'], 'date_value' => $submission->due_date],
                ];

                foreach ($values as $value) {
                    $typedValue = collect($value)->except('request_field_definition_id')->first();

                    if ($typedValue === null) {
                        continue;
                    }

                    DB::table('submission_field_values')->insert([
                        'submission_id' => $submission->id,
                        'request_field_definition_id' => $value['request_field_definition_id'],
                        ...collect($value)->except('request_field_definition_id')->all(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }
};
