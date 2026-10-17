<?php

namespace Tests\Feature\Backups;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * Promote puts the site into maintenance mode. In tests that state must stay
 * inside one application: parallel workers share storage/, so the file driver
 * made other workers' requests answer 503.
 */
class MaintenanceModeIsolationTest extends TestCase
{
    public function test_maintenance_mode_does_not_leave_this_application(): void
    {
        $downFile = storage_path('framework/down');
        $existedBefore = is_file($downFile);

        Artisan::call('down', ['--retry' => 60]);

        try {
            $this->assertTrue($this->app->isDownForMaintenance());
            $this->assertSame($existedBefore, is_file($downFile), 'Maintenance mode must not write the shared down file.');

            // Stands in for another parallel worker; then point the globals back here.
            $other = $this->createApplication();
            Container::setInstance($this->app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->app);

            $this->assertFalse($other->isDownForMaintenance(), 'Another application must not see this one in maintenance mode.');
        } finally {
            Artisan::call('up');
        }

        $this->assertFalse($this->app->isDownForMaintenance());
    }
}
