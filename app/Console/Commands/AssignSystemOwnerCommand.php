<?php

namespace App\Console\Commands;

use App\Models\SystemOwner;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignSystemOwnerCommand extends Command
{
    protected $signature = 'owner:assign {email : Email address of the existing active account}';

    protected $description = 'Fill a missing system owner without transferring an existing owner';

    public function handle(): int
    {
        if (SystemOwner::query()->exists()) {
            $this->error('A system owner already exists. This command will not overwrite it.');

            return self::FAILURE;
        }

        $target = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();
        if ($target === null) {
            $this->error('No user exists with that email address.');

            return self::FAILURE;
        }

        if (! $target->is_active) {
            $this->error('The target user must be active.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($target): void {
            if (SystemOwner::query()->lockForUpdate()->exists()) {
                throw new \RuntimeException('A system owner was assigned concurrently.');
            }

            User::query()->whereKeyNot($target)->role('super-admin')->each(function (User $legacy): void {
                $legacy->assignRole('admin');
                $legacy->removeRole('super-admin');
            });

            $target->assignRole('super-admin');
            SystemOwner::query()->create([
                'id' => SystemOwner::SINGLETON_ID,
                'user_id' => $target->id,
            ]);
        });

        $this->info("System owner assigned to {$target->email}.");

        return self::SUCCESS;
    }
}
