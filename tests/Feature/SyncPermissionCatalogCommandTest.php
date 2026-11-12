<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SyncPermissionCatalogCommandTest extends TestCase
{
    public function test_help_describes_catalog_effects_without_connecting(): void
    {
        $this->assertSame(0, Artisan::call('help', ['command_name' => 'clinic:sync-permissions']));
        $help = Artisan::output();
        foreach (['already migrated PostgreSQL', 'usuarios.administrar', 'pacientes.gestionar', 'medicos.gestionar', 'horarios.gestionar', 'citas.consultar', 'citas.programar', 'citas.pagar', 'citas.reprogramar', 'citas.cancelar', 'citas.asistencia', 'citas.atender', 'citas.agenda', 'citas.historial', 'reportes.generar', 'bitacora.consultar', 'perfil.actualizar', 'informacion.declarar', 'historial.consultar', 'password hashes', 'Idempotent'] as $text) {
            $this->assertStringContainsString($text, $help);
        }
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_non_pgsql_drivers_are_refused_without_writes(): void
    {
        $database = $this->app['db'];
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->once()->andReturn('sqlsrv');
        $connection->shouldReceive('transaction')->never();
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $this->assertSame(1, Artisan::call('clinic:sync-permissions'));
        $this->assertStringContainsString('Unable to synchronize permissions; stop and contact the operator.', Artisan::output());
        $this->assertSame([], $database->getConnections());
    }

    public function test_operational_failures_are_generic_and_never_leak(): void
    {
        $database = $this->app['db'];
        DB::shouldReceive('connection')->once()->andThrow(new RuntimeException('dsn secret host=internal'));

        $this->assertSame(1, Artisan::call('clinic:sync-permissions'));
        $output = Artisan::output();
        $this->assertStringContainsString('Unable to synchronize permissions; stop and contact the operator.', $output);
        $this->assertStringNotContainsString('dsn secret', $output);
        $this->assertSame([], $database->getConnections());
    }
}
