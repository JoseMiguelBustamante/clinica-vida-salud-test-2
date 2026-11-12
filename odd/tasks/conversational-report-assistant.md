# Conversational report assistant
Objective: let staff tell the assistant what report they need (type + dates); missing params must be asked back; then generate and allow Q&A. Date-only (no clock hours). Repository: test-2 only | Branch: feat/clinic-bootstrap | Delivery: ask-on-risk | Checks: PHPUnit focused (+ screen)

## Specs
S1. "Mi idea es que el asistente me ayuden a generar reportes y al mismo tiempo poder hacer preguntas sobre ese reporte" — assistant helps generate the report and answers questions about that same report.
S2. "Con tipo y fecha que le decimos debería de preguntarnos" — with type and dates provided to the assistant, staff can ask/interact without a separate mandatory Generar-first gate.
S3. "le tendría que decir al asistente las fechas y hora si no sé la doy no podría generar el reporte entonces tiene que consultarme si me falta algún parámetros tienen que consultarme" — required params come from the user via the assistant; if any required parameter is missing, the assistant must ask (not invent).
S4. "No elegir decile al asistente para que el haga la elección" — primary path is telling the assistant so it selects the report criteria (manual filters are secondary).
S5. "Sip" (to date-only recommendation) — assistant v1 uses calendar dates only (`Y-m-d` / ranges), not clock-hour windows. "Hasta dónde consultar" maps to `fecha_fin`.
S6. "Sip continuemos" — implement this conversational flow on test-2 and publish when verified.

## Tasks
T1 | S3,S5 | inline | done | ReportAiService planning: ready vs clarify; never invent missing dates; unit tests.
T2 | S1-S4 | inline | done | Controller + AskReportAssistantRequest conversational path; keep complete-params legacy path green.
T3 | S1-S4 | inline | done | Reports UI/README: assistant enabled without prior generate; show clarifications; demote manual filters.
T4 | S6 | inline | pending | Focused verification, conventional commit, push test-2/feat/clinic-bootstrap.

## Log
L1. User request chain (verbatim fragments): "Mi idea es que el asistente me ayuden a generar reportes y al mismo tiempo poder hacer preguntas sobre ese reporte"; "Con tipo y fecha que le decimos debería de preguntarnos"; "le tendría que decir al asistente las fechas y hora si no sé la doy no podría generar el reporte entonces tiene que consultarme si me falta algún parámetros tienen que consultarme"; "No elegir decile al asistente para que el haga la elección"; date-only closed with "Sip"; implement with "Sip continuemos".
L2. Recommendation accepted: calendar dates only; clock hours out of v1. Work only in /home/agent/projects/clinica-vida-salud-test-2. No .env reads/writes. Existing full-suite debt remains disclosed and out of scope unless this change regresses focused report/AI tests.
L3. Mid-implementation tool batch was interrupted; resumed after user "Porque no avanzas" / "Si debería de tener el cambio...". Focused PHPUnit 25 passed before publish.
