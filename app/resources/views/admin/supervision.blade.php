@extends('layouts.app', ['title' => 'Acompanhamento | Tela do Agente - Thconect'])

@section('body')
<div class="app-shell"><x-sidebar />
    <main class="workspace supervision-workspace">
        <header class="page-heading supervision-heading">
            <div><p class="eyebrow">OPERAÇÃO EM TEMPO REAL</p><h1>Acompanhamento de agentes</h1></div>
            <div class="supervision-connection" id="supervisionConnection"><i></i><span>Conectando ramal supervisor</span></div>
        </header>
        @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif

        <section class="supervision-command">
            <div class="command-copy"><p class="eyebrow">MESA DE SUPERVISÃO</p><h2>Veja a operação como ela está agora.</h2><p>Estados, duração e intervenção na chamada em um único lugar. Toda supervisão é registrada para auditoria.</p></div>
            <label>Empresa monitorada<select id="supervisionTenant">@foreach($tenants as $tenant)<option value="{{ $tenant->id }}" @selected($selectedTenantId == $tenant->id)>{{ $tenant->name }}</option>@endforeach</select></label>
            <button class="button button-soft" type="button" id="refreshSupervision">Atualizar agora</button>
        </section>

        <section class="agent-overview panel">
            <div class="overview-head"><div><p class="eyebrow">AGENTES</p><h2>Estado da equipe e consolidado de hoje</h2></div><strong><span id="agentTotal">0</span> ramais</strong></div>
            <div class="state-counters" id="stateCounters">
                @foreach(['talking' => ['Falando','#078775'], 'calling' => ['Chamando','#3155e7'], 'available' => ['Disponível','#10aee6'], 'paused' => ['Em pausa','#e89c00'], 'offline' => ['Offline','#8796ad']] as $state => [$label,$color])
                    <div data-state-counter="{{ $state }}" style="--state-color:{{ $color }}"><b>0</b><span>{{ $label }}</span><i></i></div>
                @endforeach
            </div>
            <div class="agent-search"><span aria-hidden="true">⌕</span><input id="agentSearch" type="search" placeholder="Pesquisar por agente, e-mail ou ramal" autocomplete="off"></div>
            <div class="supervision-table-wrap"><table class="supervision-table"><thead><tr><th>Agente</th><th>Status</th><th>Tempo logado</th><th>Ligações</th><th>Em chamada</th><th>Pausas</th><th>Ações</th></tr></thead><tbody id="supervisionAgents"><tr><td colspan="7" class="empty-cell">Carregando agentes…</td></tr></tbody></table></div>
        </section>

    </main>
</div>
<aside class="operator-day-drawer" id="operatorDayDrawer" hidden aria-label="Resumo diário do operador">
    <div class="operator-day-head"><div><p class="eyebrow">AÇÕES DO OPERADOR</p><h2 id="operatorDayName">Resumo do dia</h2><small id="operatorDayIdentity"></small></div><button type="button" id="operatorDayClose" aria-label="Fechar">×</button></div>
    <label class="operator-day-date">Data<input type="date" id="operatorDayDate" value="{{ now()->toDateString() }}"></label>
    <div class="operator-day-cards" id="operatorDayCards"></div>
    <section><h3>Tempo por pausa</h3><div class="pause-breakdown" id="operatorPauseBreakdown"></div></section>
    <section><h3>Linha do tempo</h3><div class="operator-timeline" id="operatorTimeline"></div></section>
</aside>
<div class="operator-day-backdrop" id="operatorDayBackdrop" hidden></div>
<section class="spy-console" id="spyConsole" hidden aria-live="polite" aria-label="Mesa de escuta ativa">
    <div class="spy-console-title"><span class="spy-eye" aria-hidden="true">◉</span><div><p id="spyConsoleKicker">MODO SPY</p><strong id="spyAgentName">Acompanhamento</strong></div></div>
    <div class="spy-console-signal"><div class="spy-wave" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div><p id="spyConsoleStatus">Aguardando uma chamada do agente.</p></div>
    <div class="spy-console-controls"><span>Falar com:</span><div class="spy-mode-switch" role="group" aria-label="Modo de supervisão"><button type="button" data-spy-mode="listen">Só ouvir</button><button type="button" data-spy-mode="whisper">Agente</button><button type="button" data-spy-mode="barge">Todos</button></div></div>
    <div class="spy-console-actions"><button type="button" class="spy-info-button" id="spyOpenDetails">Ver info</button><button type="button" class="spy-exit-button" id="spyExit">Sair</button></div>
</section>
<audio id="supervisionAudio" autoplay></audio><div class="supervision-toast" id="supervisionToast" hidden></div>
<script>window.__SUPERVISION_CONFIG__ = {{ Illuminate\Support\Js::from(['uri' => 'sip:'.$credentials['sip_user'].'@'.$credentials['sip_host'], 'password' => $credentials['sip_pass'], 'domain' => $credentials['sip_host'], 'websocketUrl' => $credentials['sip_ws_uri'], 'agentsUrl' => route('admin.supervision.agents'), 'dailyUrl' => url('/administracao/acompanhamento/ramais'), 'startUrl' => url('/administracao/acompanhamento/ramais'), 'finishUrl' => url('/administracao/acompanhamento/sessoes')]) }};</script>
@endsection
