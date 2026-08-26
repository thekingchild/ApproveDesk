<?php

namespace Tests\Unit\Config;

use Illuminate\Support\Env;
use Tests\TestCase;

class AppTimezoneTest extends TestCase
{
    public function test_missing_app_timezone_defaults_to_utc(): void
    {
        $this->withAppTimezoneEnv(null, function (): void {
            $config = require base_path('config/app.php');

            $this->assertSame('UTC', $config['timezone']);
        });
    }

    public function test_app_timezone_environment_variable_is_honoured(): void
    {
        $this->withAppTimezoneEnv('Africa/Lagos', function (): void {
            $config = require base_path('config/app.php');

            $this->assertSame('Africa/Lagos', $config['timezone']);
        });
    }

    public function test_example_env_declares_app_timezone_without_changing_version(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^APP_TIMEZONE=UTC$/m', $example);
        $this->assertMatchesRegularExpression('/^APP_VERSION=2\\.7\\.1$/m', $example);
    }

    private function withAppTimezoneEnv(?string $value, callable $callback): void
    {
        $repository = Env::getRepository();
        $previous = $repository->has('APP_TIMEZONE') ? $repository->get('APP_TIMEZONE') : null;
        $had = $repository->has('APP_TIMEZONE');

        try {
            if ($value === null) {
                $repository->clear('APP_TIMEZONE');
            } else {
                $repository->set('APP_TIMEZONE', $value);
            }

            $callback();
        } finally {
            if ($had && is_string($previous)) {
                $repository->set('APP_TIMEZONE', $previous);
            } else {
                $repository->clear('APP_TIMEZONE');
            }
        }
    }
}
