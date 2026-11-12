# Appointments (CU08–CU16, CU20–CU22)

Owns the public `GET /` landing page and the appointment workflow through
administrative reports.

CU08 consults availability (`citas.consultar`). Showing a slot does not reserve
it. CU09 books (`citas.programar`) as Programada. Consultation payment
(`citas.pagar`) starts Stripe Checkout in BOB for a Programada visit; the
webhook confirms `pago_consulta` and moves the appointment to Confirmada.
Cash/manual confirmation via CU12 remains available. CU10 reschedules the same
row (`citas.reprogramar`). CU11 cancels (`citas.cancelar`) to Cancelada and
discards pending reminders. CU12 (`citas.asistencia`) records confirmation,
arrival and Inasistencia.

CU13 (`GET|POST /citas/{cita}/atender`) requires `citas.atender`. The physician
marks Programada or Confirmada as Atendida on their linked agenda.

CU14 (`GET /agenda`, `citas.agenda`) is a read-only agenda by physician, date and
optional state. The Médico sees only their linked agenda. Recepcionista and
Administrador select a registered physician. Results are ordered by start time.
The consult reason is shown only when the actor may view it. Empty results are
not an error.

CU15 (`GET /historial`, `citas.historial`) is a read-only patient history for
Recepcionista and Administrador. Search by CI or name, then select the patient.
An optional estado filter narrows the list; an empty filter returns the complete
history, including finished visits. Empty results are not an error. Clinical notes
and diagnoses are omitted.

CU20 (`GET /historial-medico`, `historial.consultar`) is a read-only portal
consult for Paciente. It requires an approved `paciente.id_usuario` link. The
link does not grant diagnoses or professional notes. Empty authorized results
are not an error and do not mean the patient has no clinical records. A
retrieval failure is not shown as that empty state. English `/medical-history`
stays 404.

CU16 (`GET /reportes`, `reportes.generar`) is read-only for Administrador. The
available reports are appointments on a date, by physician, specialty or status,
registered patients, period totals of Atendida/Cancelada/Inasistencia, and each
physician's daily agenda. Empty results are not an error. A processing failure
is not shown as an empty list or a total of zero. After generating a report the
UI shows KPI cards and allows export as PDF, CSV or Excel
(`GET /reportes/exportar`). Clinical notes and diagnoses are omitted.

CU21 plans `recordatorio_cita` after a successful booking when the patient
already had reminders enabled with a verified email. Confirmation is not the
trigger. The default lead time is 24 hours; if that instant has already passed
and the visit has not started, the notice is planned as soon as possible.
Successful reschedule discards undelivered notices of the previous interval and
may plan a different notice only when the original booking was eligible.
Enabling the preference later does not backfill earlier appointments or
reactivate discarded rows. Changing the global lead time does not recalculate
existing plans.

The task command `clinic:send-reminders` is the sending actor. It rechecks
vigency, start time and preferences, requests send from the mail provider and
records `AceptadoPorProveedor`, `Fallido` or `ResultadoDesconocido`. Provider
acceptance is not proof of delivery. Failed, unknown or accepted notices are
not retried automatically. Sending does not confirm, register arrival or mark
the visit as attended. A planning failure after a successful booking leaves an
`IncidenciaPlanificacionRecordatorio` event; it does not undo the appointment.

Disabling reminder preferences (CU18) discards `Pendiente` and `EnProceso`
rows for appointments linked through `paciente.id_usuario`. Rows already
accepted by the provider are left unchanged.

HTML forms use CSRF, not Idempotency-Key. English `/appointments` stays 404.
The physician account must be linked on the physician record (`id_usuario`).
