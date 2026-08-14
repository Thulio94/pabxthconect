@extends('layouts.app', ['title' => 'Telefone | Tela do Agente - Thconect'])

@section('body')
<div class="app-shell phone-app">
    <x-phone-sidebar :extension="$agent['extension']" />
    <main class="workspace phone-workspace">
        <header class="page-heading">
            <div><p class="eyebrow">THCONECT PHONE</p><h1>Meu ramal</h1></div>
            <div class="agent-presence-control"><select id="agentPauseSelect" aria-label="Selecionar pausa"><option value="">Disponível</option>@foreach($pauseReasons as $pause)<option value="{{ $pause->id }}">{{ $pause->name }}{{ $pause->max_minutes ? ' · '.$pause->max_minutes.' min' : '' }}</option>@endforeach</select><button class="button button-soft" id="agentPauseButton" type="button">Aplicar</button><div class="connection-pill" id="secureConnection"><i></i> WebRTC com conexão segura</div></div>
        </header>

        <section class="line-status" aria-live="polite">
            <div class="line-pulse"><span class="status-orb" id="statusOrb"></span></div>
            <div class="line-identity">
                <p class="mini-label">ESTADO DA LINHA</p>
                <div class="status-title"><strong id="statusName">Conectando</strong><code id="statusTimer">00:00</code></div>
                <p class="muted">Ramal <b>{{ $agent['extension'] }}</b></p>
            </div>
            <p class="line-message" id="lineMessage">Registrando o ramal no servidor SIP…</p>
        </section>

        <div class="phone-grid">
            <section class="panel dial-card">
                <div class="panel-head">
                    <div><p class="eyebrow">NOVA CHAMADA</p><h2 id="callTitle">Para quem vamos ligar?</h2></div>
                    <span class="call-direction" id="callDirection">Linha livre</span>
                </div>
                <form class="manual-call visible" id="manualCallForm">
                    <label for="phone">Número de destino</label>
                    <div><input id="phone" inputmode="numeric" autocomplete="tel" placeholder="(00) 00000-0000" aria-describedby="phoneHelp" disabled><button class="button button-primary" id="callButton" type="submit" disabled>Ligar</button></div>
                    <small id="phoneHelp" aria-live="polite">Informe DDD + telefone, com 10 ou 11 dígitos. Espaços e caracteres são removidos automaticamente.</small>
                </form>

                <div class="active-call" id="activeCall" hidden>
                    <div><p class="mini-label" id="activeCallLabel">EM CHAMADA</p><strong id="activeNumber">—</strong><span class="recording-indicator" id="recordingIndicator" hidden><i></i> Gravando</span></div>
                    <div class="call-controls">
                        <button type="button" class="control-button" id="muteButton" aria-pressed="false"><span>◉</span>Silenciar</button>
                        <button type="button" class="control-button" id="holdButton" aria-pressed="false"><span>Ⅱ</span>Espera</button>
                        <button type="button" class="control-button danger" id="hangupButton"><span>×</span>Desligar</button>
                    </div>
                </div>

                <div class="incoming-call" id="incomingCall" hidden>
                    <div class="incoming-ring"><span>☎</span></div>
                    <div><p class="mini-label">CHAMADA RECEBIDA</p><strong id="incomingNumber">Número não identificado</strong></div>
                    <div class="incoming-actions"><button class="button answer-button" id="answerButton" type="button">Atender</button><button class="button reject-button" id="rejectButton" type="button">Recusar</button></div>
                </div>
            </section>

            <aside class="panel audio-console" id="audioConsole">
                    <div class="audio-console-head">
                        <div><p class="eyebrow">CONSOLE DE ÁUDIO</p><h2>Dispositivos da chamada</h2></div>
                        <div class="audio-console-tools"><span class="audio-permission pending" id="audioPermission">Verificando</span><button class="audio-console-toggle" id="audioConsoleToggle" type="button" aria-expanded="true">Ocultar</button></div>
                    </div>

                    <div class="audio-channel input-channel">
                        <div class="audio-channel-icon" aria-hidden="true">MIC</div>
                        <div class="audio-channel-body">
                            <label for="microphoneSelect">Entrada — microfone</label>
                            <select id="microphoneSelect" disabled><option>Dispositivo padrão</option></select>
                            <div class="audio-level-row">
                                <span id="microphoneState">Aguardando permissão</span>
                                <div class="audio-meter" role="meter" aria-label="Nível do microfone" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><i id="microphoneLevel"></i></div>
                            </div>
                            <div class="audio-control-row">
                                <label class="volume-label" for="microphoneVolume"><span>Volume do microfone</span><output id="microphoneVolumeValue" for="microphoneVolume">100%</output></label>
                                <input class="volume-slider" id="microphoneVolume" type="range" min="0" max="200" value="100" aria-label="Volume do microfone">
                                <button class="audio-mute-button" id="microphoneMuteButton" type="button" aria-pressed="false">Microfone ligado</button>
                            </div>
                        </div>
                    </div>

                    <div class="audio-channel output-channel">
                        <div class="audio-channel-icon" aria-hidden="true">OUT</div>
                        <div class="audio-channel-body">
                            <label for="speakerSelect">Saída — áudio da chamada</label>
                            <select id="speakerSelect" disabled><option>Saída padrão do navegador</option></select>
                            <span class="audio-device-note" id="speakerState">Será usado para ouvir a chamada</span>
                            <div class="audio-control-row">
                                <label class="volume-label" for="speakerVolume"><span>Volume do áudio</span><output id="speakerVolumeValue" for="speakerVolume">100%</output></label>
                                <input class="volume-slider" id="speakerVolume" type="range" min="0" max="100" value="100" aria-label="Volume do áudio de saída">
                                <button class="audio-mute-button" id="speakerMuteButton" type="button" aria-pressed="false">Áudio ligado</button>
                            </div>
                        </div>
                    </div>

                    <div class="audio-test-guide">
                        <p><strong>Teste do microfone:</strong> fale normalmente; você verá o medidor e ouvirá sua voz por 10 segundos na saída selecionada.</p>
                        <p><strong>Teste de áudio:</strong> reproduz um bip na saída selecionada.</p>
                    </div>
                    <div class="audio-console-actions">
                        <button class="button audio-check-button" id="audioPreflightButton" type="button">Verificar tudo</button>
                        <button class="button button-primary" id="testMicrophoneButton" type="button">Testar microfone</button>
                        <button class="button button-soft" id="testSpeakerButton" type="button">Testar áudio de saída</button>
                    </div>
                    <p class="audio-console-message" id="audioConsoleMessage" aria-live="polite">Use um fone para o retorno do microfone e evitar microfonia.</p>
            </aside>
        </div>

        <section class="panel appointment-panel" id="appointments">
            <div class="appointment-heading">
                <div><p class="eyebrow">AGENDA DO RAMAL</p><h2>Próximos retornos</h2><p>O aviso aparece nesta tela quando chegar a hora.</p></div>
                <span class="appointment-count" id="appointmentCount">0 agendamentos</span>
            </div>
            <div class="appointment-layout">
                <form id="appointmentForm" class="appointment-form">
                    <label>Nome<input id="appointmentName" name="name" maxlength="120" placeholder="Nome do contato" autocomplete="name" required></label>
                    <label>Telefone<input id="appointmentPhone" name="phone" inputmode="numeric" autocomplete="tel" placeholder="(00) 00000-0000" required></label>
                    <label>Data e hora<input id="appointmentDate" name="scheduled_for" type="datetime-local" required></label>
                    <button class="button button-primary" type="submit">Agendar retorno</button>
                    <p class="appointment-form-message" id="appointmentFormMessage" aria-live="polite"></p>
                </form>
                <div class="appointment-timeline" id="appointmentList" aria-live="polite">
                    <div class="appointment-empty">Nenhum retorno agendado. Use o formulário para criar o primeiro.</div>
                </div>
            </div>
        </section>

        <section class="panel history-panel" id="history">
            <div class="panel-head"><div><p class="eyebrow">HISTÓRICO DO RAMAL</p><h2>{{ $historyInfiniteEnabled ? 'Resultado da busca' : 'Últimas chamadas de hoje' }}</h2></div><span class="quiet-label" id="historyCount">{{ $history->count() }} {{ $history->count() === 1 ? 'chamada' : 'chamadas' }}</span></div>
            <form class="history-filters" method="GET" action="{{ route('phone.dashboard') }}#history">
                <label>De<input type="date" name="from" value="{{ $filters['from'] }}"></label>
                <label>Até<input type="date" name="to" value="{{ $filters['to'] }}"></label>
                <label>Telefone<input type="tel" name="phone" inputmode="numeric" value="{{ $filters['phone'] }}" placeholder="Somente números"></label>
                <label>Resultado
                    <select name="result">
                        <option value="">Todos</option>
                        @foreach (['completed'=>'Atendida','no_answer'=>'Não atendida','busy'=>'Ocupado','voicemail'=>'Caixa de mensagens','invalid_number'=>'Número não existe','rejected'=>'Recusada','cancelled'=>'Cancelada','unavailable'=>'Indisponível','failed'=>'Não completada','answered'=>'Em atendimento','ringing'=>'Tocando','dialing'=>'Chamando'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['result'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="history-filter-actions"><button class="button button-primary" type="submit">Filtrar</button><a class="button button-soft" href="{{ route('phone.dashboard') }}#history">Limpar</a></div>
            </form>
            <p class="history-limit-note">{{ $historyInfiniteEnabled ? 'Continue rolando para carregar todos os resultados encontrados.' : 'Exibindo somente as 25 chamadas mais recentes do dia.' }}</p>
            <div class="history-table-wrap">
                <table class="phone-history"><thead><tr><th>Número</th><th>Direção</th><th>Início</th><th>Resultado</th><th>Duração</th><th>Gravação</th></tr></thead><tbody id="historyBody">
                    @forelse($history as $call)
                        @php
                            $callData = [
                                'id' => $call->id,
                                'remote_number' => $call->to_number,
                                'direction' => $call->direction,
                                'status' => $call->status,
                                'started_at' => $call->started_at?->toIso8601String(),
                                'answered_at' => $call->answered_at?->toIso8601String(),
                                'ended_at' => $call->ended_at?->toIso8601String(),
                                'duration_seconds' => $call->duration_seconds,
                                'result_label' => $call->resultLabel(),
                                'has_recording' => $call->recording?->isPlayable() ?? false,
                                'recording_url' => $call->recording?->isPlayable() ? route('phone.call-records.recording', $call) : null,
                            ];
                            $statusLabel = $call->resultLabel();
                        @endphp
                        <tr class="history-row" data-call-id="{{ $call->id }}" data-call="{{ json_encode($callData) }}">
                            <td><button type="button" class="history-open">{{ $call->to_number ?: 'Não identificado' }}</button></td>
                            <td>{{ $call->direction === 'incoming' ? 'Recebida' : 'Realizada' }}</td>
                            <td>{{ $call->started_at?->copy()->timezone(config('app.display_timezone'))->format('d/m/Y H:i:s') }}</td>
                            <td>{{ $statusLabel }}</td>
                            <td><code>{{ gmdate('H:i:s', $call->duration_seconds) }}</code></td>
                            <td><button type="button" class="recording-cell history-open" aria-label="{{ $call->recording?->isPlayable() ? 'Ouvir gravação' : 'Ver detalhes' }}">{{ $call->recording?->isPlayable() ? '▶ Ouvir' : '—' }}</button></td>
                        </tr>
                    @empty
                        <tr class="history-empty"><td colspan="6">{{ $historyInfiniteEnabled ? 'Nenhuma chamada encontrada para estes filtros.' : 'Nenhuma chamada registrada hoje.' }}</td></tr>
                    @endforelse
                </tbody></table>
            </div>
            <div class="history-loader {{ $historyInfiniteEnabled && ! $nextHistoryCursor ? 'complete' : '' }}" id="historyLoader" @if(! $historyInfiniteEnabled) hidden @endif>
                <i aria-hidden="true"></i><span>{{ $nextHistoryCursor ? 'Role para carregar mais chamadas…' : 'Todos os resultados foram carregados.' }}</span>
            </div>
        </section>
    </main>
</div>

<audio id="remoteAudio" autoplay></audio>
<aside class="call-drawer" id="callDrawer" aria-hidden="true">
    <div class="drawer-head">
        <div class="drawer-title"><p class="eyebrow">DETALHES DA CHAMADA</p><div class="drawer-number-row"><h2 id="drawerNumber">—</h2><div class="drawer-quick-actions"><button type="button" id="drawerCallButton">Ligar</button><button type="button" id="drawerCopyButton">Copiar</button></div></div></div>
        <button type="button" class="drawer-close" id="drawerClose" aria-label="Fechar">×</button>
    </div>
    <dl class="call-detail-list">
        <div><dt>Direção</dt><dd id="drawerDirection">—</dd></div>
        <div><dt>Resultado</dt><dd id="drawerStatus">—</dd></div>
        <div><dt>Início</dt><dd id="drawerStarted">—</dd></div>
        <div><dt>Atendimento</dt><dd id="drawerAnswered">—</dd></div>
        <div><dt>Encerramento</dt><dd id="drawerEnded">—</dd></div>
        <div><dt>Duração</dt><dd id="drawerDuration">—</dd></div>
        <div><dt>Ramal</dt><dd>{{ $agent['extension'] }}</dd></div>
    </dl>
    <section class="recording-player" id="recordingPlayer" hidden>
        <p class="mini-label">GRAVAÇÃO DA CHAMADA</p>
        <audio id="recordingAudio" controls preload="metadata"></audio>
    </section>
    <div class="no-recording" id="noRecording">Esta chamada não possui gravação disponível.</div>
</aside>
<div class="drawer-backdrop" id="drawerBackdrop"></div>
<section class="appointment-alert" id="appointmentAlert" role="alertdialog" aria-live="assertive" aria-labelledby="appointmentAlertTitle" hidden>
    <div class="appointment-alert-time"><span>AGORA</span><time id="appointmentAlertTime">--:--</time></div>
    <div class="appointment-alert-copy"><p>RETORNO AGENDADO</p><h2 id="appointmentAlertTitle">—</h2><strong id="appointmentAlertPhone">—</strong></div>
    <div class="appointment-alert-actions">
        <button class="button appointment-call-button" id="appointmentCallButton" type="button">Ligar agora</button>
        <label>Adiar por
            <select id="appointmentSnoozeMinutes"><option value="5">5 minutos</option><option value="10" selected>10 minutos</option><option value="15">15 minutos</option><option value="30">30 minutos</option><option value="60">1 hora</option><option value="1440">1 dia</option></select>
        </label>
        <button class="button button-soft" id="appointmentSnoozeButton" type="button">Adiar</button>
    </div>
</section>
<script>
window.__SIP_CONFIG__ = {{ Illuminate\Support\Js::from([
    'uri' => 'sip:'.$credentials['sip_user'].'@'.$credentials['sip_host'],
    'extension' => $agent['extension'],
    'password' => $credentials['sip_pass'],
    'domain' => $credentials['sip_host'],
    'server' => $credentials['sip_host'],
    'websocketUrl' => $credentials['sip_ws_uri'],
    'iceServers' => [],
    'recordCalls' => (bool) $tenant->record_calls,
    'callsBaseUrl' => url('/telefone/chamadas'),
    'historyFilters' => $filters,
    'historyUrl' => route('phone.history'),
    'historyInfiniteEnabled' => $historyInfiniteEnabled,
    'historyNextCursor' => $nextHistoryCursor,
    'appointmentsUrl' => route('phone.appointments.index'),
    'presenceUrl' => route('phone.presence.heartbeat'),
    'pauseUrl' => route('phone.presence.pause'),
    'sessionEndedUrl' => route('phone.login'),
]) }};
</script>
@endsection
