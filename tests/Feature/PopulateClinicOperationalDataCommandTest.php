<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PopulateClinicOperationalDataCommandTest extends TestCase
{
    public function test_help_describes_operational_effects_without_connecting(): void
    {
        $this->assertSame(0, Artisan::call('help', ['command_name' => 'clinic:populate-clinic-data']));
        $help = Artisan::output();
        foreach ([
            'already migrated PostgreSQL',
            'Administrador',
            'Mon–Sat',
            'November availability',
            'CVS-',
            'CVS2-',
            '~40 patients',
            'November detail',
            'Idempotent',
            'db:seed',
            '--force',
            'login accounts',
            'demo data',
        ] as $text) {
            $this->assertStringContainsString($text, $help);
        }
        $this->assertStringNotContainsString('DEMO-', $help);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_non_interactive_runs_without_force_do_not_touch_the_database(): void
    {
        $this->assertSame(1, Artisan::call('clinic:populate-clinic-data'));
        $this->assertStringContainsString('Confirmation is required; no changes made.', Artisan::output());
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_non_pgsql_drivers_are_refused_without_writes(): void
    {
        $database = $this->app['db'];
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->once()->andReturn('sqlsrv');
        $connection->shouldReceive('transaction')->never();
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $this->assertSame(1, Artisan::call('clinic:populate-clinic-data', ['--force' => true]));
        $this->assertStringContainsString('Unable to load clinic data; stop and contact the operator.', Artisan::output());
        $this->assertSame([], $database->getConnections());
    }

    public function test_operational_failures_are_generic_and_never_leak(): void
    {
        $database = $this->app['db'];
        DB::shouldReceive('connection')->once()->andThrow(new RuntimeException('dsn secret host=internal'));

        $this->assertSame(1, Artisan::call('clinic:populate-clinic-data', ['--force' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('Unable to load clinic data; stop and contact the operator.', $output);
        $this->assertStringContainsString('RuntimeException', $output);
        $this->assertStringNotContainsString('dsn secret', $output);
        $this->assertSame([], $database->getConnections());
    }
}
