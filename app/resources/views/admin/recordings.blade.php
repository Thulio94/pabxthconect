@extends('layouts.app', ['title' => 'Gravações | Tela do Agente - Thconect'])

@section('body')
<div class="app-shell"><x-sidebar />
    <main class="workspace admin-workspace recordings-workspace">
        <header class="page-heading"><div><p class="eyebrow">CENTRAL PBX</p><h1>Gravações de chamadas</h1></div><a class="button button-soft" href="{{ route('admin.index') }}">Voltar à administração</a></header>
        <section class="panel recordings-filter"><div class="section-title"><div><p class="eyebrow">CONSULTAR</p><h2>Filtrar gravações</h2></div></div>
            <form method="GET" class="filter-form">
                <label>De<input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label><label>Até<input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label><label>Telefone<input name="phone" inputmode="tel" value="{{ $filters['phone'] ?? '' }}" placeholder="DDD + número"></label>
                <label>Empresa<select name="tenant_id"><option value="">Todas</option>@foreach($tenants as $tenant)<option value="{{ $tenant->id }}" @selected(($filters['tenant_id'] ?? null) == $tenant->id)>{{ $tenant->name }}</option>@endforeach</select></label>
                <label>Resultado<select name="status"><option value="">Todos</option><option value="answered" @selected(($filters['status'] ?? '') === 'answered')>Atendida</option><option value="failed" @selected(($filters['status'] ?? '') === 'failed')>Falhou</option><option value="busy" @selected(($filters['status'] ?? '') === 'busy')>Ocupado</option><option value="no_answer" @selected(($filters['status'] ?? '') === 'no_answer')>Sem resposta</option><option value="cancelled" @selected(($filters['status'] ?? '') === 'cancelled')>Cancelada</option></select></label>
                <div class="filter-actions"><button class="button button-primary">Filtrar</button><a class="button button-soft" href="{{ route('admin.recordings.index') }}">Limpar</a></div>
            </form></section>
        <section class="registry recordings-registry"><div class="section-title"><div><p class="eyebrow">ARQUIVOS DISPONÍVEIS</p><h2>{{ $recordings->total() }} gravação(ões)</h2></div><span class="muted">Arquivos respeitam a retenção de cada empresa.</span></div>
            <div class="table-wrap"><table><thead><tr><th>Data</th><th>Empresa</th><th>Ramal</th><th>Telefone</th><th>Resultado</th><th>Duração</th><th>Ouvir</th></tr></thead><tbody>@forelse($recordings as $recording) @php($call = $recording->call)<tr><td>{{ $call?->started_at?->format('d/m/Y H:i') ?? '—' }}</td><td>{{ $call?->tenant?->name ?? '—' }}</td><td>{{ $call?->extension?->number ?? '—' }}</td><td>{{ $call?->to_number ?? $call?->from_number ?? '—' }}</td><td>{{ match($call?->status) { 'answered' => 'Atendida', 'busy' => 'Ocupado', 'no_answer' => 'Sem resposta', 'cancelled' => 'Cancelada', 'failed' => 'Falhou', default => '—' } }}</td><td>{{ gmdate('H:i:s', $call?->duration_seconds ?? 0) }}</td><td><audio controls preload="none" src="{{ route('admin.recordings.play', $recording) }}">Seu navegador não suporta áudio.</audio></td></tr>@empty<tr><td colspan="7" class="empty-cell">Nenhuma gravação encontrada com estes filtros.</td></tr>@endforelse</tbody></table></div>
            <div class="pagination-wrap">{{ $recordings->links() }}</div></section>
    </main></div>
@endsection
