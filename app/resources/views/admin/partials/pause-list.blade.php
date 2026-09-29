@forelse($pauses as $pause)
    <details class="pause-item" data-pause-id="{{ $pause->id }}">
        <summary><i style="background:{{ $pause->color }}"></i><span><b>{{ $pause->name }}</b><small>{{ $pause->max_minutes ? $pause->max_minutes.' min' : 'sem limite' }}</small></span><em>{{ $pause->is_active ? 'Ativa' : 'Inativa' }}</em></summary>
        <div class="pause-editor">
            <form method="POST" action="{{ route('admin.pauses.update', $pause) }}" data-async-form="pauses">@csrf @method('PUT')<label>Nome<input name="name" value="{{ $pause->name }}" required></label><label>Cor<input name="color" type="color" value="{{ $pause->color }}" required></label><label>Limite<input name="max_minutes" type="number" min="1" max="480" value="{{ $pause->max_minutes }}"></label><label class="check"><input type="checkbox" name="is_active" value="1" @checked($pause->is_active)><span>Ativa</span></label><button class="button button-primary">Salvar</button></form>
            <form method="POST" action="{{ route('admin.pauses.destroy', $pause) }}" data-async-form="pauses" data-confirm-title="Excluir pausa?" data-confirm="A pausa {{ $pause->name }} deixará de estar disponível para os agentes desta empresa." data-confirm-label="Excluir pausa" data-confirm-tone="danger">@csrf @method('DELETE')<button class="button button-danger">Excluir</button></form>
        </div>
    </details>
@empty
    <div class="empty-cell">Nenhuma pausa cadastrada para esta empresa.</div>
@endforelse
