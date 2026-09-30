@extends('layouts.app', ['title' => 'Acompanhamento da equipe | Thconect'])

@section('body')
<div class="app-shell"><x-sidebar />
    <main class="workspace admin-workspace supervisor-workspace">
        <header class="page-heading"><div><p class="eyebrow">{{ $tenant->name }}</p><h1>Acompanhamento da equipe</h1></div><span class="connection-pill" id="supervisorUpdated"><i></i> Atualizando…</span></header>
        <section class="supervision-command supervisor-intro">
            <div class="command-copy"><p class="eyebrow">VISÃO DA EQUIPE</p><h2>Operadores da sua empresa</h2><p>Esta tela mostra apenas quem está online ou offline. O perfil supervisor não pode abrir o telefone nem intervir nas chamadas.</p></div>
            <a class="button button-soft" href="{{ route('supervisor.recordings.index') }}">Ouvir gravações</a>
        </section>
        <section class="agent-overview panel supervisor-agent-overview">
            <div class="overview-head"><div><p class="eyebrow">AGENTES</p><h2>Estado atual dos operadores</h2></div><button type="button" class="button button-soft" id="refreshSupervisorAgents">Atualizar</button></div>
            <div class="state-counters supervisor-counters">
                <div style="--state-color:#078775"><b id="supervisorOnlineCount">0</b><span>Online</span><i></i></div>
                <div style="--state-color:#8796ad"><b id="supervisorOfflineCount">0</b><span>Offline</span><i></i></div>
            </div>
            <div class="supervision-table-wrap"><table class="supervision-table"><thead><tr><th>Agente</th><th>E-mail</th><th>Ramal</th><th>Status</th></tr></thead><tbody id="supervisorAgents"><tr><td colspan="4" class="empty-cell">Carregando equipe…</td></tr></tbody></table></div>
            <p class="supervisor-error" id="supervisorAgentsError" role="status" aria-live="polite" hidden>Não foi possível atualizar a equipe. Tente novamente.</p>
        </section>
    </main>
</div>
<script>window.__SUPERVISOR_CONFIG__ = {{ Illuminate\Support\Js::from(['agentsUrl' => route('supervisor.agents')]) }};</script>
@endsection
