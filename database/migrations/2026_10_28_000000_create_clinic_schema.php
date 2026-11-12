<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('The clinic schema requires PostgreSQL.');
        }

        $sql = file_get_contents(database_path('schema/clinic.sql'));

        if ($sql === false) {
            throw new RuntimeException('The approved clinic schema SQL is unavailable.');
        }

        DB::unprepared($sql);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS
            public.bitacora,
            public.pago_consulta,
            public.evento_cita,
            public.recordatorio_cita,
            public.registro_atencion,
            public.preferencias_privacidad,
            public.preferencias_notificacion,
            public.contacto_emergencia,
            public.informacion_medica,
            public.cita_medica,
            public.horario_atencion,
            public.medico_especialidad,
            public.especialidad,
            public.medico,
            public.paciente,
            public.rol_permiso,
            public.usuario,
            public.permiso,
            public.rol');
    }
};
