<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

class ClinicMigrationTest extends TestCase
{
    public function createApplication(): Application
    {
        $root = dirname(__DIR__, 2);
        $database = getenv('ISOLATED_PG_DATABASE') ?: '';
        $password = getenv('ISOLATED_PG_PASSWORD') ?: '';
        $port = getenv('ISOLATED_PG_PORT') ?: '';

        if (getenv('CLINIC_DISPOSABLE_PG') !== 'yes'
            || ! preg_match('/^clinic_migration_test_[a-f0-9]{16}$/', $database)
            || $password === '' || ! ctype_digit($port) || (int) $port < 1024
            || getenv('APP_CONFIG_CACHE') !== false || is_file($root.'/bootstrap/cache/config.php')) {
            throw new \RuntimeException('Run tests/Integration/run-postgres.sh against its owned disposable PostgreSQL container.');
        }

        $app = parent::createApplication();
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql', 'url' => null, 'host' => '127.0.0.1', 'port' => $port,
            'database' => $database, 'username' => $database, 'password' => $password,
            'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable',
        ]);
        $app['config']->set('database.default', 'pgsql');

        return $app;
    }

    public function test_report_schema_is_atomic_repeatable_constrained_and_reversible_on_an_empty_fixture(): void
    {
        $connection = DB::connection();
        $identity = $connection->selectOne('SELECT current_database() AS db, current_user AS usr');
        $this->assertSame(getenv('ISOLATED_PG_DATABASE'), $identity->db);
        $this->assertSame($identity->db, $identity->usr);
        $this->assertSame([], $connection->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"));

        $options = ['--force' => true, '--no-interaction' => true];
        $this->assertSame(0, Artisan::call('migrate', $options));
        $this->assertStringContainsString('create_clinic_schema', Artisan::output());
        $this->assertReportMetadata();
        $this->assertSame(2, $connection->table('migrations')->count());
        $this->assertSame(0, Artisan::call('migrate', $options));
        $this->assertStringContainsString('Nothing to migrate', Artisan::output());
        $this->assertSame(2, $connection->table('migrations')->count());

        $connection->unprepared(<<<'SQL'
            INSERT INTO rol (nombre) VALUES ('Doctor'), ('Reception');
            INSERT INTO permiso (codigo) VALUES ('fixture.read');
            INSERT INTO usuario (nombre_usuario, contrasena_hash, activo, id_rol)
                VALUES ('fixture', 'not-a-real-password-hash', true, 1);
            INSERT INTO rol_permiso (id_rol, id_permiso) VALUES (1, 1);
            INSERT INTO paciente (nombres, apellidos, activo, fecha_registro)
                VALUES ('Test', 'Patient', true, '2026-01-01T00:00:00Z');
            INSERT INTO medico (registro_profesional, nombres, apellidos, activo)
                VALUES ('FIXTURE-1', 'Test', 'Doctor', true), ('FIXTURE-2', 'Other', 'Doctor', true);
            INSERT INTO especialidad (nombre, activo) VALUES ('Fixture', true);
            INSERT INTO medico_especialidad (id_medico, id_especialidad) VALUES (1, 1);
            INSERT INTO horario_atencion (dia_semana, hora_inicio, hora_fin, duracion_cita_minutos, activo, id_medico)
                VALUES ('Lunes', '08:00', '09:00', 30, true, 1);
            INSERT INTO cita_medica (codigo, inicio, fin, modalidad, estado, fecha_creacion, id_paciente, id_medico, id_especialidad)
                VALUES ('FIXTURE-1', '2026-01-02T08:00:00Z', '2026-01-02T08:30:00Z', 'Presencial', 'Programada', '2026-01-01T00:00:00Z', 1, 1, 1);
            INSERT INTO informacion_medica (id_paciente, fecha_actualizacion) VALUES (1, '2026-01-01T00:00:00Z');
            INSERT INTO contacto_emergencia (id_paciente, nombre, parentesco, telefono) VALUES (1, 'Test', 'Parent', '000');
            INSERT INTO preferencias_notificacion (id_usuario, recordatorios_habilitados, canal, destino_verificado)
                VALUES (1, false, 'CorreoElectronico', false);
            INSERT INTO preferencias_privacidad (fecha_actualizacion, id_usuario) VALUES ('2026-01-01T00:00:00Z', 1);
            INSERT INTO registro_atencion (fecha_registro, resumen_atencion, id_cita, id_medico)
                VALUES ('2026-01-02T08:30:00Z', 'Fixture only', 1, 1);
            INSERT INTO recordatorio_cita (fecha_programada, estado, id_cita) VALUES ('2026-01-01T00:00:00Z', 'Pendiente', 1);
            INSERT INTO evento_cita (tipo, fecha_hora, id_cita) VALUES ('Fixture', '2026-01-01T00:00:00Z', 1);
            INSERT INTO bitacora (fecha_hora, accion, modulo, detalle, direccion_ip, actor_nombre, actor_rol, id_usuario)
                VALUES ('2026-01-01T00:00:00Z', 'Inicio de sesión', 'Acceso', 'Acceso correcto', '127.0.0.1', 'fixture', 'Doctor', 1);
            SQL);

        $rejections = [
            ["UPDATE horario_atencion SET dia_semana = 'Invalid'", '23514'],
            ['UPDATE horario_atencion SET hora_fin = hora_inicio', '23514'],
            ['UPDATE horario_atencion SET duracion_cita_minutos = 0', '23514'],
            ['UPDATE cita_medica SET fin = inicio', '23514'],
            ["UPDATE cita_medica SET modalidad = 'Invalid'", '23514'],
            ["UPDATE cita_medica SET estado = 'Invalid'", '23514'],
            ["UPDATE preferencias_notificacion SET canal = 'SMS'", '23514'],
            ['UPDATE preferencias_notificacion SET destino_verificado = true', '23514'],
            ["UPDATE preferencias_notificacion SET destino_verificado = true, destino = ' '", '23514'],
            ['UPDATE preferencias_notificacion SET recordatorios_habilitados = true', '23514'],
            ["UPDATE recordatorio_cita SET estado = 'Invalid'", '23514'],
            ["INSERT INTO bitacora (fecha_hora, accion, modulo, detalle) VALUES ('2026-01-01T00:00:00Z', 'Invalid', 'Acceso', 'x')", '23514'],
            ['UPDATE cita_medica SET id_medico = 2', '23503'],
            ['UPDATE medico_especialidad SET id_medico = 2147483647', '23503'],
            ["UPDATE rol SET nombre = 'Doctor' WHERE id_rol = 2", '23505'],
            ['UPDATE paciente SET nombres = NULL', '23502'],
        ];

        foreach ($rejections as [$sql, $state]) {
            $before = $this->fixtureSnapshot();
            $connection->beginTransaction();

            try {
                $connection->getPdo()->exec($sql);
                $this->fail('The report constraint accepted invalid fixture data.');
            } catch (PDOException $exception) {
                $this->assertSame($state, $exception->getCode());
            } finally {
                $connection->rollBack();
            }

            $this->assertSame($before, $this->fixtureSnapshot());
        }

        $this->assertSame(0, Artisan::call('migrate:rollback', $options));
        $this->assertSame(['migrations'], array_column($connection->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"), 'tablename'));
        $this->assertSame(0, $connection->table('migrations')->count());
        $connection->unprepared("CREATE TABLE public.cita_medica (fixture TEXT); INSERT INTO public.cita_medica VALUES ('keep-me')");
        $beforeFailure = $this->fixtureSnapshot();

        try {
            Artisan::call('migrate', $options);
            $this->fail('A conflicting table must fail without a partially created clinic schema.');
        } catch (QueryException $exception) {
            $this->assertSame('42P07', $exception->getCode());
        }

        $this->assertSame($beforeFailure, $this->fixtureSnapshot());
        $connection->statement('DROP TABLE public.cita_medica');
        $this->assertSame(0, Artisan::call('migrate', $options));
        $this->assertReportMetadata();
    }

    private function assertReportMetadata(): void
    {
        $sql = file_get_contents(database_path('schema/clinic.sql'));
        preg_match_all('/CREATE TABLE public\.(\w+) \((.*?)\n\);/s', $sql, $definitions, PREG_SET_ORDER);
        $types = ['INTEGER' => 'integer', 'TEXT' => 'text', 'DATE' => 'date', 'TIME' => 'time without time zone', 'TIMESTAMPTZ' => 'timestamp with time zone', 'BOOLEAN' => 'boolean'];
        $expected = [];
        $constraints = [];
        $indexes = [];

        foreach ($definitions as $definition) {
            preg_match_all('/^\s+(\w+) (INTEGER|VARCHAR\(\d+\)|TEXT|DATE|TIMESTAMPTZ|TIME|BOOLEAN)([^\n]*)/m', $definition[2], $columns, PREG_SET_ORDER);

            foreach ($columns as $column) {
                $expected[$definition[1]][] = [
                    'name' => $column[1],
                    'type' => $types[$column[2]] ?? str_replace('VARCHAR', 'character varying', $column[2]),
                    'required' => str_contains($column[3], 'NOT NULL'),
                    'identity' => str_contains($column[3], 'GENERATED ALWAYS') ? 'a' : '',
                ];
            }

            preg_match_all('/CONSTRAINT (\w+) (PRIMARY KEY|UNIQUE|FOREIGN KEY|CHECK)/', $definition[2], $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $constraints[$match[1]] = ['PRIMARY KEY' => 'p', 'UNIQUE' => 'u', 'FOREIGN KEY' => 'f', 'CHECK' => 'c'][$match[2]];

                if (in_array($match[2], ['PRIMARY KEY', 'UNIQUE'], true)) {
                    $indexes[] = $match[1];
                }
            }
        }

        $actual = [];
        foreach (DB::select("SELECT c.relname, a.attname, format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull, a.attidentity FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relname <> 'migrations' AND a.attnum > 0 AND NOT a.attisdropped ORDER BY c.relname, a.attnum") as $column) {
            $actual[$column->relname][] = ['name' => $column->attname, 'type' => $column->type, 'required' => (bool) $column->attnotnull, 'identity' => $column->attidentity];
        }
        ksort($expected);
        $this->assertCount(19, $expected);
        $this->assertSame($expected, $actual);

        $actualConstraints = [];
        foreach (DB::select("SELECT co.conname, co.contype FROM pg_constraint co JOIN pg_class c ON c.oid = co.conrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relname <> 'migrations' ORDER BY co.conname") as $constraint) {
            $actualConstraints[$constraint->conname] = $constraint->contype;
        }
        ksort($constraints);
        $this->assertSame($constraints, $actualConstraints);
        preg_match_all('/CREATE INDEX (\w+)/', $sql, $matches);
        $indexes = array_merge($indexes, $matches[1]);
        sort($indexes);
        $this->assertSame($indexes, array_column(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND tablename <> 'migrations' ORDER BY indexname"), 'indexname'));
    }

    private function fixtureSnapshot(): array
    {
        $snapshot = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename") as $table) {
            $snapshot[$table->tablename] = DB::select('SELECT * FROM public."'.$table->tablename.'" ORDER BY 1');
        }
        $snapshot['sequences'] = DB::select("SELECT sequencename, last_value FROM pg_sequences WHERE schemaname = 'public' ORDER BY sequencename");

        return json_decode(json_encode($snapshot), true);
    }
}
