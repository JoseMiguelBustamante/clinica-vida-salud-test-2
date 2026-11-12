<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $sheet['title'] }} — Clínica Vida Salud</title>
    <style>
        :root {
            --ink: #16352f;
            --muted: #5b736d;
            --line: #d5e3df;
            --green: #0f766e;
            --panel: #f3faf7;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: var(--ink);
            font: 14px/1.45 "Segoe UI", "Helvetica Neue", Arial, sans-serif;
            background: #eef5f2;
        }
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 2;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            justify-content: space-between;
            padding: 0.85rem 1.25rem;
            background: #ffffff;
            border-bottom: 1px solid var(--line);
        }
        .toolbar p { margin: 0; color: var(--muted); font-size: 0.92rem; }
        .toolbar button, .toolbar a {
            appearance: none;
            border: 0;
            border-radius: 0.65rem;
            padding: 0.65rem 0.95rem;
            background: var(--green);
            color: #fff;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }
        .sheet {
            width: min(100% - 1.25rem, 56rem);
            margin: 1rem auto 1.5rem;
            padding: 1.15rem 1rem 1.35rem;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 1rem;
            overflow-x: hidden;
        }
        .brand {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--green);
            margin-bottom: 1rem;
        }
        .brand h1 {
            margin: 0 0 0.25rem;
            font-size: 1.2rem;
        }
        .brand p { margin: 0; color: var(--muted); font-size: 0.9rem; }
        .summary {
            display: grid;
            gap: 0.35rem;
            margin: 0 0 1.15rem;
            padding: 0.9rem 1rem;
            background: var(--panel);
            border-radius: 0.75rem;
        }
        .summary p { margin: 0; overflow-wrap: anywhere; }
        .table-wrap {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid var(--line);
            border-radius: 0.65rem;
        }
        table {
            width: 100%;
            min-width: 36rem;
            border-collapse: collapse;
            font-size: 0.82rem;
        }
        th, td {
            padding: 0.5rem 0.4rem;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        th {
            background: var(--panel);
            color: var(--muted);
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }
        tr:nth-child(even) td { background: #fbfdff; }
        .footer {
            margin-top: 1.25rem;
            color: var(--muted);
            font-size: 0.82rem;
        }
        @media (max-width: 40rem) {
            .toolbar {
                padding: 0.75rem 0.9rem;
            }
            .sheet {
                width: calc(100% - 1rem);
                margin: 0.75rem auto 1.25rem;
                padding: 0.95rem 0.75rem 1.1rem;
            }
            table {
                font-size: 0.76rem;
                min-width: 32rem;
            }
        }
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .sheet {
                width: 100%;
                margin: 0;
                border: 0;
                border-radius: 0;
                padding: 0;
                overflow: visible;
            }
            .table-wrap {
                overflow: visible;
                border: 0;
                border-radius: 0;
            }
            table {
                min-width: 0;
                font-size: 0.78rem;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <p>Vista lista para PDF. En el diálogo de impresión elija <strong>Guardar como PDF</strong>.</p>
        <div>
            <button type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
        </div>
    </div>

    <main class="sheet">
        <header class="brand">
            <div>
                <h1>Clínica Vida Salud</h1>
                <p>Reportes administrativos</p>
            </div>
            <div>
                <p><strong>{{ $sheet['title'] }}</strong></p>
                <p>Generado: {{ $generatedAt->timezone('UTC')->format('Y-m-d H:i') }} UTC</p>
            </div>
        </header>

        <section class="summary" aria-label="Resumen">
            @foreach ($sheet['summary'] as $line)
                <p>{{ $line }}</p>
            @endforeach
        </section>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        @foreach ($sheet['headers'] as $header)
                            <th>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sheet['rows'] as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ max(count($sheet['headers']), 1) }}">No hay datos para la consulta.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="footer">{{ count($sheet['rows']) }} {{ count($sheet['rows']) === 1 ? 'registro' : 'registros' }} · Sin diagnósticos ni notas clínicas.</p>
    </main>
</body>
</html>
