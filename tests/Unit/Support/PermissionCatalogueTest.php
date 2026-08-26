<?php

namespace Tests\Unit\Support;

use App\Support\PermissionCatalogue;
use PHPUnit\Framework\TestCase;

class PermissionCatalogueTest extends TestCase
{
    public function test_catalogue_metadata_and_dependencies_are_consistent(): void
    {
        $entries = PermissionCatalogue::all();
        $keys = array_column($entries, 'key');
        $labels = array_column($entries, 'label');

        $this->assertNotEmpty($entries);
        $this->assertSame($keys, array_values(array_unique($keys)));
        $this->assertSame($labels, array_values(array_unique($labels)));
        $this->assertNotContains('super-admin', $keys);

        foreach ($entries as $entry) {
            $this->assertContains($entry['risk'], ['standard', 'elevated', 'owner_reserved']);
            $this->assertNotSame('', $entry['group']);
            foreach ($entry['depends_on_any'] as $dependency) {
                $this->assertContains($dependency, $keys);
            }
            if ($entry['risk'] === 'owner_reserved') {
                $this->assertFalse($entry['delegable']);
            }
        }
    }

    public function test_role_manager_authority_is_not_redelegable(): void
    {
        $entry = PermissionCatalogue::find('roles.manage.custom');

        $this->assertNotNull($entry);
        $this->assertFalse($entry['delegable']);
    }

    public function test_custom_code_permission_is_not_delegable(): void
    {
        $entry = PermissionCatalogue::find('branding.custom_code.manage');

        $this->assertNotNull($entry);
        $this->assertFalse($entry['delegable']);
        $this->assertSame('elevated', $entry['risk']);
        $this->assertContains('branding.manage', $entry['depends_on_any']);
        $this->assertSame([], $entry['legacy']);
    }
}
