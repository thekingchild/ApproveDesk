<?php

use App\Support\SchemaTimestamps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_otp_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('code_hash');
            SchemaTimestamps::required($table, 'expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['email', 'consumed_at']);
        });

        // Pending (unconfirmed) self-registrations are normal users whose
        // email is unverified; they already exist on users.email_verified_at.
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_otp_codes');
    }
};
