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

    public function test_example_env_declares_app_timezone_and_current_version(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^APP_TIMEZONE=UTC$/m', $example);
        $this->assertMatchesRegularExpression('/^APP_VERSION=2\.7\.3$/m', $example);
    }

    private function withAppTimezoneEnv(?string $value, callable $callback): void
    {
        $repository = Env::getRepository();
        $previousRepo = $repository->has('APP_TIMEZONE') ? $repository->get('APP_TIMEZONE') : null;
        $hadRepo = $repository->has('APP_TIMEZONE');
        $previousPut = getenv('APP_TIMEZONE');
        $previousEnv = array_key_exists('APP_TIMEZONE', $_ENV) ? $_ENV['APP_TIMEZONE'] : false;
        $previousServer = array_key_exists('APP_TIMEZONE', $_SERVER) ? $_SERVER['APP_TIMEZONE'] : false;

        try {
            if ($value === null) {
                $repository->clear('APP_TIMEZONE');
                putenv('APP_TIMEZONE');
                unset($_ENV['APP_TIMEZONE'], $_SERVER['APP_TIMEZONE']);
            } else {
                $repository->set('APP_TIMEZONE', $value);
                putenv('APP_TIMEZONE='.$value);
                $_ENV['APP_TIMEZONE'] = $value;
                $_SERVER['APP_TIMEZONE'] = $value;
            }

            $callback();
        } finally {
            if ($hadRepo && is_string($previousRepo)) {
                $repository->set('APP_TIMEZONE', $previousRepo);
            } else {
                $repository->clear('APP_TIMEZONE');
            }

            if ($previousPut === false) {
                putenv('APP_TIMEZONE');
            } else {
                putenv('APP_TIMEZONE='.$previousPut);
            }

            if ($previousEnv === false) {
                unset($_ENV['APP_TIMEZONE']);
            } else {
                $_ENV['APP_TIMEZONE'] = $previousEnv;
            }

            if ($previousServer === false) {
                unset($_SERVER['APP_TIMEZONE']);
            } else {
                $_SERVER['APP_TIMEZONE'] = $previousServer;
            }
        }
    }
}
