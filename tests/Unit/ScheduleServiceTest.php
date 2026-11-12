<?php

namespace Tests\Unit;

use App\Modules\MedicalStaff\PhysicianNotFound;
use App\Modules\MedicalStaff\PhysicianRepository;
use App\Modules\Schedules\InvalidScheduleInput;
use App\Modules\Schedules\OverlappingSchedule;
use App\Modules\Schedules\ScheduleBlocksExistingAppointments;
use App\Modules\Schedules\ScheduleRepository;
use App\Modules\Schedules\ScheduleService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ScheduleServiceTest extends TestCase
{
    public function test_a_missing_physician_is_rejected_before_writes(): void
    {
        $schedules = Mockery::mock(ScheduleRepository::class);
        $physicians = Mockery::mock(PhysicianRepository::class);
        $physicians->shouldReceive('getPhysicianById')->once()->with(4)->andReturn(null);
        $schedules->shouldReceive('createSchedule')->never();

        try {
            (new ScheduleService($schedules, $physicians))->createSchedule([
                'id_medico' => 4,
                'dia_semana' => 'Lunes',
                'hora_inicio' => '08:00',
                'hora_fin' => '12:00',
                'duracion_cita_minutos' => 30,
                'activo' => true,
            ]);
            $this->fail('Expected PhysicianNotFound');
        } catch (PhysicianNotFound) {
        }

        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_duration_must_fill_the_window_with_complete_slots(): void
    {
        $schedules = Mockery::mock(ScheduleRepository::class);
        $physicians = Mockery::mock(PhysicianRepository::class);
        $physicians->shouldReceive('getPhysicianById')->once()->with(4)
            ->andReturn((object) ['id_medico' => 4, 'activo' => true]);
        $schedules->shouldReceive('hasOverlappingActiveSchedule')->never();
        $schedules->shouldReceive('createSchedule')->never();

        try {
            (new ScheduleService($schedules, $physicians))->createSchedule([
                'id_medico' => 4,
                'dia_semana' => 'Lunes',
                'hora_inicio' => '08:00',
                'hora_fin' => '12:00',
                'duracion_cita_minutos' => 45,
                'activo' => true,
            ]);
            $this->fail('Expected InvalidScheduleInput');
        } catch (InvalidScheduleInput) {
        }

        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_overlapping_active_fringes_are_rejected_before_writes(): void
    {
        $schedules = Mockery::mock(ScheduleRepository::class);
        $physicians = Mockery::mock(PhysicianRepository::class);
        $physicians->shouldReceive('getPhysicianById')->once()->with(4)
            ->andReturn((object) ['id_medico' => 4, 'activo' => true]);
        $schedules->shouldReceive('hasOverlappingActiveSchedule')->once()
            ->with(4, 'Lunes', '08:00:00', '12:00:00', null)->andReturn(true);
        $schedules->shouldReceive('lockPhysicianSchedules')->never();
        $schedules->shouldReceive('createSchedule')->never();

        try {
            (new ScheduleService($schedules, $physicians))->createSchedule([
                'id_medico' => 4,
                'dia_semana' => 'Lunes',
                'hora_inicio' => '08:00',
                'hora_fin' => '12:00',
                'duracion_cita_minutos' => 30,
                'activo' => true,
            ]);
            $this->fail('Expected OverlappingSchedule');
        } catch (OverlappingSchedule) {
        }

        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_shrinking_a_window_is_refused_when_appointments_would_fall_outside(): void
    {
        $schedules = Mockery::mock(ScheduleRepository::class);
        $physicians = Mockery::mock(PhysicianRepository::class);
        $schedules->shouldReceive('getScheduleById')->once()->with(9)->andReturn((object) [
            'id_horario' => 9,
            'dia_semana' => 'Lunes',
            'hora_inicio' => '08:00:00',
            'hora_fin' => '12:00:00',
            'duracion_cita_minutos' => 30,
            'activo' => true,
            'id_medico' => 4,
        ]);
        $schedules->shouldReceive('scheduledAppointmentsWouldFallOutside')->once()
            ->with(4, 'Lunes', '08:00:00', '12:00:00', 'Lunes', '08:00:00', '10:00:00')
            ->andReturn(true);
        $schedules->shouldReceive('updateSchedule')->never();

        try {
            (new ScheduleService($schedules, $physicians))->updateSchedule(9, [
                'dia_semana' => 'Lunes',
                'hora_inicio' => '08:00',
                'hora_fin' => '10:00',
                'duracion_cita_minutos' => 30,
            ]);
            $this->fail('Expected ScheduleBlocksExistingAppointments');
        } catch (ScheduleBlocksExistingAppointments) {
        }

        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_missing_weekday_windows_are_created_for_active_physicians_only(): void
    {
        $schedules = Mockery::mock(ScheduleRepository::class);
        $physicians = Mockery::mock(PhysicianRepository::class);
        $physicians->shouldReceive('getAllPhysicians')->once()->with(null)->andReturn(collect([
            (object) ['id_medico' => 4, 'activo' => true],
            (object) ['id_medico' => 5, 'activo' => false],
        ]));
        $schedules->shouldReceive('getAllSchedules')->once()->with(null)->andReturn(collect([
            (object) ['id_medico' => 4, 'dia_semana' => 'Lunes', 'activo' => true],
        ]));
        $physicians->shouldReceive('getPhysicianById')->times(5)->with(4)
            ->andReturn((object) ['id_medico' => 4, 'activo' => true]);
        $schedules->shouldReceive('hasOverlappingActiveSchedule')->times(10)->andReturn(false);
        $schedules->shouldReceive('lockPhysicianSchedules')->times(5)->with(4);
        $schedules->shouldReceive('createSchedule')->times(5)->andReturnUsing(function (array $values): int {
            $this->assertSame(4, $values['id_medico']);
            $this->assertSame('08:00:00', $values['hora_inicio']);
            $this->assertSame('18:00:00', $values['hora_fin']);
            $this->assertSame(30, $values['duracion_cita_minutos']);
            $this->assertTrue($values['activo']);

            return 1;
        });
        DB::shouldReceive('transaction')->times(5)->andReturnUsing(fn (callable $callback) => $callback());
        DB::shouldReceive('getConnections')->andReturn([]);

        $created = (new ScheduleService($schedules, $physicians))
            ->ensureMissingWeekdaySchedulesForActivePhysicians();

        $this->assertSame(5, $created);
        $this->assertSame([], DB::getConnections());
    }
}
