@php
    /** @var object $entry */
@endphp
<div class="activity-diff">
    @if ($entry->changes !== [])
        <p class="activity-diff-title">{{ $entry->detalle }}</p>
        <div class="table-wrap activity-diff-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Campo</th>
                        <th>Valor anterior</th>
                        <th>Valor nuevo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entry->changes as $change)
                        <tr>
                            <td>{{ $change['campo'] }}</td>
                            <td>{{ $change['valor_anterior'] ?? '—' }}</td>
                            <td>{{ $change['valor_nuevo'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="list-hint">Este movimiento no registra cambios de campos.</p>
    @endif
    @if ($entry->id_registro)
        <p class="list-hint">ID de registro: {{ $entry->id_registro }}</p>
    @endif
</div>
