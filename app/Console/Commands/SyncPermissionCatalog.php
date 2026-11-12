<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\PermissionCatalogService;
use Illuminate\Console\Command;
use Throwable;

class SyncPermissionCatalog extends Command
{
    protected $signature = 'clinic:sync-permissions';

    protected $description = 'Define clinic roles and permission codes without changing passwords or accounts';

    protected $help = <<<'HELP'
        Requires an already migrated PostgreSQL database. Creates missing rol, permiso and
        rol_permiso rows from the clinic catalog. Existing usernames, emails and password hashes
        are not changed.         Administrador receives usuarios.administrar, pacientes.gestionar,
        especialidades.gestionar, medicos.gestionar, horarios.gestionar, citas.consultar, citas.programar, citas.pagar, citas.reprogramar, citas.cancelar, citas.asistencia, citas.atender, citas.agenda, citas.historial, reportes.generar and bitacora.consultar;
        Recepcionista receives pacientes.gestionar, citas.consultar, citas.programar, citas.pagar, citas.reprogramar, citas.cancelar, citas.asistencia, citas.agenda and citas.historial;
        Médico receives citas.atender and citas.agenda;
        Paciente receives perfil.actualizar, informacion.declarar and historial.consultar. Idempotent: safe to rerun. No password prompts, arguments, defaults
        or environment writes. Signed-in requests apply missing catalog rows automatically; this
        command is a manual repair. On any error, stop and contact the operator.
        HELP;

    public function __construct(private PermissionCatalogService $catalog)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $this->catalog->synchronize();
        } catch (Throwable) {
            $this->error('Unable to synchronize permissions; stop and contact the operator.');

            return self::FAILURE;
        }

        $this->info('Roles and permission codes are defined. Existing accounts were not changed.');

        return self::SUCCESS;
    }
}
