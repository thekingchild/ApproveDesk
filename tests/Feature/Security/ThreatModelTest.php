<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class ThreatModelTest extends TestCase
{
    public function test_threat_model_covers_required_surfaces(): void
    {
        $path = base_path('docs/adr/0002-security-and-privacy-threat-models.md');

        $this->assertFileExists($path);

        $contents = strtolower((string) file_get_contents($path));

        foreach (['authentication', 'files', 'integrations', 'workflow actions', 'exports', 'saas tenancy'] as $surface) {
            $this->assertStringContainsString($surface, $contents, $surface.' missing from the threat model');
        }

        $this->assertFileExists(base_path('docs/SECURITY.md'));
        $this->assertStringContainsString('secrets:rotate-webhooks', (string) file_get_contents(base_path('docs/SECURITY.md')));
    }
}
