<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Http\Controllers;

use App\Modules\Appointments\AppointmentAttendanceNotAllowed;
use App\Modules\Appointments\AppointmentNotAttendable;
use App\Modules\Appointments\AppointmentNotCancellable;
use App\Modules\Appointments\AppointmentNotFound;
use App\Modules\Appointments\AppointmentNotReschedulable;
use App\Modules\Appointments\AppointmentReminderService;
use App\Modules\Appointments\AppointmentService;
use App\Modules\Appointments\AvailabilityService;
use App\Modules\Appointments\ConsultationPaymentService;
use App\Modules\Appointments\Http\Requests\CancelAppointmentRequest;
use App\Modules\Appointments\Http\Requests\ConfirmAppointmentRequest;
use App\Modules\Appointments\Http\Requests\PrepareAppointmentRequest;
use App\Modules\Appointments\Http\Requests\PrepareRescheduleRequest;
use App\Modules\Appointments\Http\Requests\RecordAppointmentCareRequest;
use App\Modules\Appointments\Http\Requests\RegisterAbsenceRequest;
use App\Modules\Appointments\Http\Requests\RegisterArrivalRequest;
use App\Modules\Appointments\Http\Requests\RescheduleAppointmentRequest;
use App\Modules\Appointments\Http\Requests\SearchAgendaRequest;
use App\Modules\Appointments\Http\Requests\SearchAppointmentsRequest;
use App\Modules\Appointments\Http\Requests\SearchPatientHistoryRequest;
use App\Modules\Appointments\Http\Requests\SendAppointmentReminderRequest;
use App\Modules\Appointments\Http\Requests\StoreAppointmentRequest;
use App\Modules\Appointments\InvalidAvailabilityInput;
use App\Modules\Appointments\PatientNotEligible;
use App\Modules\Appointments\PhysicianNotEligible;
use App\Modules\Appointments\ReminderNotSendable;
use App\Modules\Appointments\SlotUnavailable;
use App\Modules\Identity\ActivityLogService;
use App\Modules\Identity\PermissionCatalog;
use App\Modules\Patients\PatientNotFound;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

final class AppointmentController
{
    public function __construct(
        private AppointmentService $appointments,
        private AvailabilityService $availability,
        private AppointmentReminderService $reminders,
        private ConsultationPaymentService $consultationPayments,
        private ActivityLogService $activity,
    ) {}

    public function getAllAppointments(SearchAppointmentsRequest $request): View
    {
        $input = $request->validated();

        return view('appointments.index', [
            'appointments' => $this->appointments->getAllAppointments(
                $input['q'] ?? null,
                $input['fecha'] ?? null,
                $input['estado'] ?? null,
            ),
            'q' => $input['q'] ?? '',
            'fecha' => $input['fecha'] ?? '',
            'estado' => $input['estado'] ?? '',
        ]);
    }

    public function showCreateAppointmentForm(PrepareAppointmentRequest $request): View
    {
        $input = $request->validated();
        $specialtyId = isset($input['especialidad']) ? (int) $input['especialidad'] : null;
        $physicians = collect();
        $status = null;
        try {
            if ($specialtyId !== null) {
                $physicians = $this->availability->getConsultPhysicians($specialtyId);
            }
        } catch (InvalidAvailabilityInput) {
            $status = 'Revise la especialidad seleccionada.';
        } catch (Throwable $exception) {
            Log::error('[AppointmentController - showCreateAppointmentForm] '.$exception::class);
            $status = 'No fue posible cargar los médicos de esa especialidad.';
        }

        return view('appointments.create', [
            'patients' => $this->appointments->getBookablePatients($input['q'] ?? null),
            'specialties' => $this->availability->getConsultSpecialties(),
            'physicians' => $physicians,
            'q' => $input['q'] ?? '',
            'id_paciente' => isset($input['id_paciente']) ? (int) $input['id_paciente'] : null,
            'especialidad' => $specialtyId,
            'medico' => isset($input['medico']) ? (int) $input['medico'] : null,
            'fecha' => $input['fecha'] ?? '',
            'hora_inicio' => $input['hora_inicio'] ?? '',
            'hora_fin' => $input['hora_fin'] ?? '',
            'status' => $status,
        ]);
    }

    public function createAppointment(StoreAppointmentRequest $request): RedirectResponse
    {
        $input = $request->validated();
        $actor = $request->attributes->get('identity');
        try {
            $appointmentId = $this->appointments->createAppointment([
                'id_paciente' => (int) $input['id_paciente'],
                'id_especialidad' => (int) $input['id_especialidad'],
                'id_medico' => (int) $input['id_medico'],
                'fecha' => $input['fecha'],
                'hora_inicio' => $input['hora_inicio'],
                'hora_fin' => $input['hora_fin'],
                'modalidad' => $input['modalidad'],
                'motivo_consulta' => $input['motivo_consulta'] ?? null,
            ], (int) $actor->id_usuario);
        } catch (PatientNotEligible) {
            return back()->withInput()->withErrors(['id_paciente' => 'El paciente no está habilitado para una cita.']);
        } catch (PhysicianNotEligible) {
            return back()->withInput()->withErrors(['id_medico' => 'El médico no está habilitado para esa especialidad.']);
        } catch (SlotUnavailable) {
            return back()->withInput()->withErrors(['hora_inicio' => 'Ese intervalo ya no está disponible. Consulte otra fecha u hora.']);
        } catch (InvalidAvailabilityInput) {
            return back()->withInput()->withErrors(['fecha' => 'Revise la fecha, el intervalo y la modalidad.']);
        } catch (Throwable $exception) {
            Log::error('[AppointmentController - createAppointment] '.$exception::class);

            return back()->withInput()->withErrors(['id_paciente' => 'No fue posible registrar la cita.']);
        }

        $this->activity->record('Creación', 'Citas', 'Cita programada', $appointmentId);

        if (($input['origen'] ?? '') === 'disponibilidad') {
            return redirect()->route('availability.consult', array_filter([
                'fecha' => $input['fecha'],
                'especialidad' => $input['id_especialidad'],
                'medico' => $input['id_medico'],
                'vista' => 'semana',
            ]))->with('status', 'La cita quedó programada.');
        }

        return redirect()->route('appointments.show', $appointmentId)
            ->with('status', 'La cita quedó programada.');
    }

    public function getAppointmentById(Request $request, int $cita): View|RedirectResponse
    {
        $actor = $request->attributes->get('identity');
        $codes = $request->attributes->get('identity_permissions', []);
        $restrictedToOwnAgenda = in_array(PermissionCatalog::MARK_ATTENDED, $codes, true)
            && ! in_array(PermissionCatalog::BOOK_APPOINTMENTS, $codes, true)
            && ! in_array(PermissionCatalog::RESCHEDULE_APPOINTMENTS, $codes, true);

        try {
            $appointment = $restrictedToOwnAgenda
                ? $this->appointments->getAppointmentForCare($cita, (int) $actor->id_usuario)
                : $this->appointments->getAppointmentById($cita);

            $canManageReminders = in_array(PermissionCatalog::BOOK_APPOINTMENTS, $codes, true);
            $reminder = null;
            if ($canManageReminders) {
                try {
                    $reminder = $this->reminders->overviewForAppointment($cita);
                } catch (Throwable $exception) {
                    Log::error('[AppointmentController - reminder overview] '.$exception::class);
                }
            }

            $paymentOverview = null;
            if (in_array(PermissionCatalog::PAY_CONSULTATIONS, $codes, true)) {
                try {
                    $paymentOverview = $this->consultationPayments->overviewForAppointment($cita);
                } catch (Throwable $exception) {
                    Log::error('[AppointmentController - payment overview] '.$exception::class);
                }
            }

            return view('appointments.show', [
                'appointment' => $appointment,
                'reminder' => $reminder,
                'canManageReminders' => $canManageReminders,
                'paymentOverview' => $paymentOverview,
            ]);
        } catch (AppointmentNotFound) {
            return $restrictedToOwnAgenda
                ? redirect()->route('appointments.agenda')->withErrors(['q' => 'No puede realizarse la operación solicitada.'])
                : redirect()->route('appointments.create')->withErrors(['id_paciente' => 'No puede realizarse la operación solicitada.']);
        } catch (AppointmentNotAttendable) {
            return redirect()->route('appointments.agenda')
                ->withErrors(['q' => 'No puede consultar una cita que no está en su agenda.']);
        }
    }

    public function sendAppointmentReminder(SendAppointmentReminderRequest $request, int $cita): RedirectResponse
    {
        try {
            $this->appointments->getAppointmentById($cita);
            $result = $this->reminders->sendPendingReminderNow($cita);
            $this->activity->record(
                'Envío',
                'Citas',
                'Prueba de recordatorio de la cita '.$cita.' ('.$result.')',
                $cita,
            );
        } catch (AppointmentNotFound) {
            return redirect()->route('appointments.index')
                ->withErrors(['q' => 'No puede realizarse la operación solicitada.']);
        } catch (ReminderNotSendable) {
            return redirect()->route('appointments.show', $cita)
                ->withErrors(['q' => 'No se puede enviar el recordatorio ahora. Revise preferencias del paciente y el estado del aviso.']);
        } catch (Throwable $exception) {
            Log::error('[AppointmentController - sendAppointmentReminder] '.$exception::class);

            return redirect()->route('appointments.show', $cita)
                ->withErrors(['q' => 'No fue posible solicitar el envío del recordatorio.']);
        }

        $message = match ($result) {
            'AceptadoPorProveedor' => 'El correo aceptó el recordatorio. Revise la bandeja (y spam) del paciente.',
            'Fallido' => 'El proveedor rechazó el envío. Revise la configuración de correo.',
            default => 'El envío quedó con resultado '.$result.'.',
        };

        return redirect()->route('appointments.show', $cita)->with('status', $message);
    }

    public function showRescheduleAppointmentForm(PrepareRescheduleRequest $request, int $cita): View|RedirectResponse
    {
        try {
            $appointment = $this->appointments->getAppointmentById($cita);
        } catch (AppointmentNotFound) {
            return redirect()->route('appointments.index')
                ->withErrors(['q' => 'No puede realizarse la operación solicitada.']);
        }
        if (! in_array((string) $appointment->estado, ['Programada', 'Confirmada'], true)) {
            return redirect()->route('appointments.show', $cita)
                ->withErrors(['fecha' => 'El estado de esta cita no permite reprogramarla.']);
        }

        $input = $request->validated();
        $date = $input['fecha'] ?? '';
        $startClock = $input['hora_inicio'] ?? '';
        $endClock = $input['hora_fin'] ?? '';
        $slots = null;
        $status = null;
        try {
            if ($date !== '') {
                $slots = $this->availability->getAvailableSlots(
                    (int) $appointment->id_especialidad,
                    (int) $appointment->id_medico,
                    $date,
                    $cita,
                );
            }
        } catch (InvalidAvailabilityInput) {
            $status = 'Revise la fecha consultada.';
        } catch (PhysicianNotEligible) {
            $status = 'El médico no está habilitado para esa especialidad.';
        } catch (Throwable $exception) {
            Log::error('[AppointmentController - showRescheduleAppointmentForm] '.$exception::class);
            $status = 'No fue posible obtener la disponibilidad.';
        }

        return view('appointments.reschedule', [
            'appointment' => $appointment,
            'fecha' => $date,
            'hora_inicio' => $startClock,
            'hora_fin' => $endClock,
            'slots' => $slots,
            'status' => $status,
        ]);
    }

    public function rescheduleAppointment(RescheduleAppointmentRequest $request, int $cita): RedirectResponse
    {
        $input = $request->validated();
        $actor = $request->attributes->get('identity');
        try {
            $result = $this->appointments->rescheduleAppointment($cita, [
                'fecha' => $input['fecha'],
                'hora_inicio' => $input['hora_inicio'],
                'hora_fin' => $input['hora_fin'],
            ], (int) $actor->id_usuario);
        } catch (AppointmentNotFound) {
            return redirect()->route('appointments.index')
                ->withErrors(['q' => 'No puede realizarse la operación solicitada.']);
        } catch (AppointmentNotReschedulable) {
            return redirect()->route('appointments.show', $cita)
                ->withErrors(['fecha' => 'El estado de esta cita no permite reprogramarla.']);
        } catch (PhysicianNotEligible) {
            return back()->withInput()->withErrors(['fecha' => 'El médico no está habilitado para esa especialidad.']);
        } catch (SlotUnavailable) {
            return back()->withInput()->withErrors(['hora_inicio' => 'Ese intervalo ya no está disponible. Consulte otra fecha u hora.']);
        } catch (InvalidAvailabilityInput) {
            return back()->withInput()->withErrors(['fecha' => 'Indique un intervalo distinto, válido y no pasado.']);
        } catch (Throwable $exception) {
            Log::error('[AppointmentController - rescheduleAppointment] '.$exception::class);

            return back()->withInput()->withErrors(['fecha' => 'No fue posible reprogramar la cita.']);
        }

        $this->activity->record('Reprogramación', 'Citas', 'Cita reprogramada', $cita);

        $message = $result['required_new_confirmation']
            ? 'La cita quedó reprogramada. Requiere una nueva confirmación de asistencia.'
            : 'La cita quedó reprogramada.';

        return redirect()->route('appointments.show', $cita)->with('status', $message);
    }

    public function showCancelAppointmentForm(int $cita): View|RedirectResponse
    {
        try {
            $appointment = $this->appointments->getAppointmentById($cita);
        } catch (AppointmentNotFound) {
            return redirect()->route('appointments.index')
                ->withErrors(['q' => 'No puede realizarse la operación solicitada.']);
        }
        if (! in_array((string) $appointment->estado, ['Programada', 'Confirmada'], true)) {
            return redirect()->route('appointments.show', $cita)
                ->withErrors(['motivo_cancelacion' => 'El estado de esta cita no permite cancelarla.']);
        }

        return view('appointments.cancel', [
            'appointment' => $appointment,
        ]);
    }

    public function cancelAppointment(CancelAppointmentRequest $request, int $cita): RedirectResponse
    {
        $input = $request->validated();
        $actor = $request->attributes->get('identity');
        try {
            $this->appointments->cancelAppointment(
                $cita,
                $input['motivo_cancelacion'] ?? null,
                (int) $actor->id_usuario,
            );
        } catch (AppointmentNotFound) {
            return redirect()->route('appointments.index')
                ->withErrors(['q' => 'No puede realizarse la operación solicitada.']);
        } catch (AppointmentNotCancellable) {
            return redirect()->route('appointments.show', $cita)
                ->withErrors(['motivo_cancelacion' => 'El estado de esta cita no permite cancelarla.']);
        } catch (Throwable $exception) {
            Log::error('[AppointmentController - cancelAppointment] '.$exception::class);

            return back()->withInput()->withErrors(['motivo_cancelacion' => 'No fue posible cancelar la cita.']);
        }

        $this->activity->record('Cancelación', 'Citas', 'Cita cancelada', $cita);

        return redirect()->route('appointments.show', $cita)
            ->with('status', 'La cita quedó cancelada. El intervalo queda libre para otra programación.');
    }

    public function showAttendanceForm(int $cita): View|RedirectResponse
    {
        try {
            return view('appointments.attendance', [
                'appointment' => $this->appointments->getAppointmentById($cita),
            ]);
        } catch (AppointmentNotFound) {
            return redirect()->route('appointments.index')
                ->withErrors(['q' => 'No puede realizarse la operación solicitada.']);
        }
    }

    public function confirmAppointment(ConfirmAppointmentRequest $request, int $cita): RedirectResponse
    {
        $actor = $request->attributes->get('identity');
        try {
            $this->appointments->confirmAppointment($cita, (int) $actor->id_usuario);
        } catch (AppointmentNotFound) {
            return redirect()->route('appointments.index')
                ->withErrors(['q' => 'No puede realizarse la operación solicitada.']);
        } catch (AppointmentAttendanceNotAllowed) {
            return redirect()->route('appointments.attendance', $cita)
                ->withErrors(['estado' => 'La cita no está Programada. Si ya está Confirmada, no se repite la operación.']);
        } catch (Throwable $exception) {
            Log::error('[AppointmentController - confirmAppointment] '.$exception::class);

            return back()->withErrors(['estado' => 'No fue posible registrar la confirmación.']);
        }

        return redirect()->route('appointments.show', $cita)
            ->with('status', 'La cita quedó Confirmada. Eso no acredita llegada ni atención médica.');
    }

    public function registerAppointmentArrival(RegisterArrivalRequest $request, int $cita): RedirectResponse
    {
        $actor = $request->attributes->get('identity');
        try {
            $this->appointments->registerAppointmentArrival($cita, (int) $actor->id_usuario);
        } catch (AppointmentNotFound) {
            return redirect()->route('appointments.index')
                ->withErrors(['q' => 'No puede realizarse la operación solicitada.']);
        } catch (AppointmentAttendanceNotAllowed) {
            return redirect()->route('appointments.attendance', $cita)
                ->withErrors(['estado' => 'No se puede registrar la llegada. La cita está cancelada, atendida, con inasistencia o ya tiene llegada.']);
        } catch (Throwable $exception) {
            Log::error('[AppointmentController - registerAppointmentArrival] '.$exception::class);

            return back()->withErrors(['estado' => 'No fue posible registrar la llegada.']);
        }

        return redirect()->route('appointments.show', $cita)
            ->with('status', 'Se registró la llegada. Eso no marca la cita como Atendida.');
    }

    public function registerAppointmentAbsence(RegisterAbsenceRequest $request, int $cita): RedirectResponse
    {
        $actor = $request->attributes->get('identity');
        try {
            $this->appointments->registerAppointmentAbsence($cita, (int) $actor->id_usuario);
        } catch (AppointmentNotFound) {
            return redirect()->route('appointments.index')
                ->withErrors(['q' => 'No puede realizarse la operación solicitada.']);
        } catch (AppointmentAttendanceNotAllowed) {
            return redirect()->route('appointments.attendance', $cita)
                ->withErrors(['verificado' => 'La inasistencia solo se registra después del fin del intervalo, si no hay llegada y la cita no está Cancelada ni Atendida.']);
        } catch (Throwable $exception) {
            Log::error('[AppointmentController - registerAppointmentAbsence] '.$exception::class);

            return back()->withErrors(['verificado' => 'No fue posible registrar la inasistencia.']);
        }

        return redirect()->route('appointments.show', $cita)
            ->with('status', 'La cita quedó en Inasistencia. El transcurso del tiempo no la cambia solo.');
    }

    public function getPhysicianAgenda(SearchAgendaRequest $request): View
    {
        $input = $request->validated();
        $actor = $request->attributes->get('identity');
        $codes = $request->attributes->get('identity_permissions', []);
        $canSelectPhysician = $this->canSelectAnyAgendaPhysician($codes);
        $canViewReason = $this->canViewAgendaReason($codes);
        $status = null;
        $result = [
            'appointments' => null,
            'physicians' => collect(),
            'physician_id' => null,
            'fecha' => $input['fecha'] ?? '',
            'estado' => $input['estado'] ?? '',
        ];
        try {
            $result = $this->appointments->consultPhysicianAgenda(
                (int) $actor->id_usuario,
                [
                    'fecha' => $input['fecha'] ?? null,
                    'id_medico' => isset($input['id_medico']) ? (int) $input['id_medico'] : null,
                    'estado' => $input['estado'] ?? null,
                ],
                $canSelectPhysician,
            );
        } catch (AppointmentNotAttendable) {
            $status = 'Su cuenta no está vinculada a un médico activo. En Médicos, asocie la cuenta de acceso.';
        } catch (PhysicianNotEligible) {
            $status = 'El médico seleccionado no está registrado.';
        } catch (InvalidAvailabilityInput) {
            $status = 'Indique una fecha válida y, si corresponde, un médico.';
        } catch (Throwable $exception) {
            Log::error('[AppointmentController - getPhysicianAgenda] '.$exception::class);
            $status = 'No fue posible consultar la agenda.';
        }

        return view('appointments.agenda', [
            'appointments' => $result['appointments'],
            'physicians' => $result['physicians'],
            'physician_id' => $result['physician_id'],
            'fecha' => $result['fecha'],
            'estado' => $result['estado'],
            'canSelectPhysician' => $canSelectPhysician,
            'canViewReason' => $canViewReason,
            'canMarkAttended' => in_array(PermissionCatalog::MARK_ATTENDED, $codes, true),
            'status' => $status,
        ]);
    }

    public function showAttendAppointmentForm(Request $request, int $cita): View|RedirectResponse
    {
        $actor = $request->attributes->get('identity');
        try {
            return view('appointments.attend', [
                'appointment' => $this->appointments->getAppointmentForCare($cita, (int) $actor->id_usuario),
            ]);
        } catch (AppointmentNotFound) {
            return redirect()->route('appointments.agenda')
                ->withErrors(['q' => 'No puede realizarse la operación solicitada.']);
        } catch (AppointmentNotAttendable) {
            return redirect()->route('appointments.agenda')
                ->withErrors(['q' => 'No puede marcar esta cita. Solo su agenda y estados Programada o Confirmada.']);
        }
    }

    public function recordAppointmentCare(RecordAppointmentCareRequest $request, int $cita): RedirectResponse
    {
        $input = $request->validated();
        $actor = $request->attributes->get('identity');
        try {
            $this->appointments->recordAppointmentCare($cita, [
                'resumen_atencion' => $input['resumen_atencion'] ?? null,
                'diagnostico' => $input['diagnostico'] ?? null,
                'indicaciones' => $input['indicaciones'] ?? null,
            ], (int) $actor->id_usuario);
        } catch (AppointmentNotFound) {
            return redirect()->route('appointments.agenda')
                ->withErrors(['q' => 'No puede realizarse la operación solicitada.']);
        } catch (AppointmentNotAttendable) {
            return redirect()->route('appointments.attend', $cita)
                ->withErrors(['verificado' => 'La cita no permite registrar atención. Si ya está Atendida, no se repite. Cancelada o Inasistencia tampoco.']);
        } catch (Throwable $exception) {
            Log::error('[AppointmentController - recordAppointmentCare] '.$exception::class);

            return back()->withInput()->withErrors(['verificado' => 'No fue posible registrar la atención.']);
        }

        return redirect()->route('appointments.show', $cita)
            ->with('status', 'La cita quedó Atendida. La llegada o la confirmación no sustituyen este registro.');
    }

    public function consultPatientHistory(SearchPatientHistoryRequest $request): View
    {
        $input = $request->validated();
        $status = null;
        $result = [
            'matches' => collect(),
            'patient' => null,
            'appointments' => null,
            'q' => $input['q'] ?? '',
            'estado' => $input['estado'] ?? '',
        ];
        try {
            $result = $this->appointments->consultPatientHistory([
                'q' => $input['q'] ?? null,
                'id_paciente' => isset($input['id_paciente']) ? (int) $input['id_paciente'] : null,
                'estado' => $input['estado'] ?? null,
            ]);
        } catch (PatientNotFound) {
            $status = 'El paciente no existe o no puede identificarse.';
        } catch (InvalidAvailabilityInput) {
            $status = 'El filtro de estado no es válido.';
        } catch (Throwable $exception) {
            Log::error('[AppointmentController - consultPatientHistory] '.$exception::class);
            $status = 'No fue posible consultar el historial.';
        }

        return view('appointments.history', [
            'matches' => $result['matches'],
            'patient' => $result['patient'],
            'appointments' => $result['appointments'],
            'q' => $result['q'],
            'estado' => $result['estado'],
            'status' => $status,
        ]);
    }

    /**
     * @param  list<string>  $codes
     */
    private function canSelectAnyAgendaPhysician(array $codes): bool
    {
        return in_array(PermissionCatalog::CONSULT_AVAILABILITY, $codes, true)
            || in_array(PermissionCatalog::BOOK_APPOINTMENTS, $codes, true)
            || in_array(PermissionCatalog::RESCHEDULE_APPOINTMENTS, $codes, true);
    }

    /**
     * @param  list<string>  $codes
     */
    private function canViewAgendaReason(array $codes): bool
    {
        return in_array(PermissionCatalog::BOOK_APPOINTMENTS, $codes, true)
            || in_array(PermissionCatalog::RESCHEDULE_APPOINTMENTS, $codes, true)
            || in_array(PermissionCatalog::RECORD_ATTENDANCE, $codes, true)
            || in_array(PermissionCatalog::MARK_ATTENDED, $codes, true);
    }
}
