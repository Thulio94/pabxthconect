@extends('layouts.app', ['title' => 'Central PBX | Tela do Agente - Thconect'])

@section('body')
<div class="app-shell">
    <x-sidebar />
    <main class="workspace admin-workspace pbx-admin">
        <header class="page-heading">
            <div><p class="eyebrow">CENTRAL PBX</p><h1>Empresas, rotas e ramais</h1></div>
            <span class="connection-pill"><i></i> Configuração aplicada no PBX</span>
        </header>

        @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        @if(session('route_test'))<div class="alert alert-success">{{ session('route_test.message') }}</div>@endif
        @if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif

        @if(session('new_extension_credentials'))
            @php($credentials = session('new_extension_credentials'))
            <section class="credential-reveal" role="status">
                <div><p class="eyebrow">CREDENCIAL GERADA AGORA</p><h2>{{ $credentials['name'] }} · ramal {{ $credentials['extension'] }}</h2><p>Login: <b>{{ $credentials['email'] }}</b>. Copie a senha antes de sair; ela só mudará se o administrador gerar outra.</p></div>
                <code>{{ $credentials['password'] }}</code>
            </section>
        @endif

        <section class="pbx-rail" aria-label="Resumo operacional">
            <div><span>{{ $trunks->count() }}</span><small>rotas SIP</small></div>
            <div><span>{{ $tenants->count() }}</span><small>empresas</small></div>
            <div><span>{{ $tenants->sum(fn ($tenant) => $tenant->extensions->count()) }}</span><small>ramais</small></div>
            <div><span>{{ $tenants->sum(fn ($tenant) => $tenant->phoneLicenseLeases->count()) }}/{{ $tenants->sum('concurrent_agent_limit') }}</span><small>licenças em uso</small></div>
            <p>Primeiro cadastre uma rota. Depois associe-a à empresa e crie os ramais.</p>
        </section>

        <div class="pbx-form-grid">
            <section class="panel">
                <p class="eyebrow">01 · ROTA DE SAÍDA</p><h2>Adicionar rota SIP</h2>
                <p class="muted">Para TECH, o softswitch autoriza o IP do PBX. Para usuário e senha, as credenciais permanecem criptografadas.</p>
                <form method="POST" action="{{ route('admin.trunks.store') }}" class="stack-form">
                    @csrf
                    <label>Nome da rota<input name="name" required placeholder="Softswitch principal"></label>
                    <div class="form-pair"><label>Autenticação<select name="auth_mode"><option value="ip_tech">TECH / IP autorizado</option><option value="userpass">Usuário e senha</option></select></label><label>Transporte<select name="transport"><option value="udp">UDP</option><option value="tcp">TCP</option><option value="tls">TLS</option></select></label></div>
                    <div class="form-pair"><label>Host ou IP<input name="host" required placeholder="203.0.113.10"></label><label>Porta<input name="port" type="number" value="5060" min="1" max="65535" required></label></div>
                    <label>Prefixo TECH <span class="optional">obrigatório para TECH/IP</span><input name="tech_prefix" inputmode="numeric" placeholder="8033"></label>
                    <div class="form-pair"><label>Usuário SIP <span class="optional">apenas rota autenticada</span><input name="username" autocomplete="off"></label><label>Senha SIP <span class="optional">apenas rota autenticada</span><input name="password" type="password" autocomplete="new-password"></label></div>
                    <button class="button button-primary" type="submit">Salvar rota</button>
                </form>
            </section>

            <section class="panel">
                <p class="eyebrow">02 · EMPRESA</p><h2>Criar empresa</h2>
                <p class="muted">A empresa controla o intervalo dos seus ramais e por quanto tempo as gravações serão guardadas.</p>
                <form method="POST" action="{{ route('admin.tenants.store') }}" class="stack-form">
                    @csrf
                    <label>Nome da empresa<input name="name" required></label>
                    <label>Identificador interno<input name="slug" required placeholder="cliente-exemplo"></label>
                    <label>Licenças simultâneas<input name="concurrent_agent_limit" type="number" min="1" max="10000" value="{{ old('concurrent_agent_limit', 1) }}" required><small>Quantidade máxima de agentes usando a tela de telefonia ao mesmo tempo.</small></label>
                    <label>Retenção das gravações<select name="recording_retention_days"><option value="30">30 dias</option><option value="60">60 dias</option><option value="90" selected>90 dias</option><option value="180">180 dias</option><option value="365">365 dias</option><option value="0">Sem expiração automática</option></select></label>
                    <label class="check"><input type="checkbox" name="record_calls" value="1" checked><span>Gravar chamadas realizadas</span></label>
                    <button class="button button-primary" type="submit">Criar empresa</button>
                </form>
            </section>

        </div>

        <section class="registry pbx-registry">
            <div class="section-title"><div><p class="eyebrow">ROTAS CADASTRADAS</p><h2>Saída do PBX</h2></div></div>
            <div class="route-list">
                @forelse($trunks as $trunk)
                    <details class="route-row"><summary><span class="route-glyph">↗</span><div><strong>{{ $trunk->name }}</strong><small>{{ $trunk->auth_mode === 'ip_tech' ? 'TECH/IP autorizado · TECH '.$trunk->tech_prefix : 'Usuário e senha protegidos' }}</small></div><code>{{ $trunk->host }}:{{ $trunk->port }}</code><span class="route-use">{{ $trunk->tenants_count }} empresas</span><span class="button button-soft">Gerenciar</span></summary><div class="crud-editor"><form method="POST" action="{{ route('admin.trunks.update', $trunk) }}" class="stack-form">@csrf @method('PUT')<div class="form-pair"><label>Nome<input name="name" value="{{ $trunk->name }}" required></label><label>Modo<select name="auth_mode"><option value="ip_tech" @selected($trunk->auth_mode === 'ip_tech')>TECH/IP</option><option value="userpass" @selected($trunk->auth_mode === 'userpass')>Usuário/senha</option></select></label></div><div class="form-pair"><label>Host<input name="host" value="{{ $trunk->host }}" required></label><label>Porta<input name="port" type="number" value="{{ $trunk->port }}" required></label></div><div class="form-pair"><label>Transporte<select name="transport"><option value="udp" @selected($trunk->transport === 'udp')>UDP</option><option value="tcp" @selected($trunk->transport === 'tcp')>TCP</option><option value="tls" @selected($trunk->transport === 'tls')>TLS</option></select></label><label>TECH<input name="tech_prefix" value="{{ $trunk->tech_prefix }}"></label></div><div class="form-pair"><label>Usuário SIP<input name="username" value="{{ $trunk->username }}"></label><label>Nova senha <span class="optional">vazio mantém</span><input name="password" type="password"></label></div><label class="check"><input type="checkbox" name="is_active" value="1" @checked($trunk->is_active)><span>Rota ativa</span></label><div class="crud-actions"><button class="button button-primary">Salvar rota</button></div></form><div class="crud-actions"><form method="POST" action="{{ route('admin.trunks.test', $trunk) }}">@csrf<button class="button button-soft">Testar rota</button></form><form method="POST" action="{{ route('admin.trunks.destroy', $trunk) }}" data-confirm-title="Excluir rota?" data-confirm="A rota e todos os vínculos com empresas serão removidos. Esta ação não pode ser desfeita." data-confirm-label="Excluir rota" data-confirm-tone="danger">@csrf @method('DELETE')<button class="button button-danger">Excluir rota</button></form></div></div></details>
                @empty
                    <div class="empty-state">Nenhuma rota cadastrada. Adicione a rota que entregará as chamadas ao softswitch.</div>
                @endforelse
            </div>
        </section>

        <section class="registry pbx-registry">
            <div class="section-title"><div><p class="eyebrow">DIAGNÓSTICO DE DISCAGEM</p><h2>Falhas das últimas 24 horas</h2><p class="muted">Confirme aqui o destino efetivamente montado pelo Asterisk. O padrão correto é TECH + 55 + DDD + número.</p></div></div>
            <div class="table-wrap"><table><thead><tr><th>Hora</th><th>Empresa</th><th>Ramal</th><th>Rota</th><th>Destino enviado</th><th>Retorno</th></tr></thead><tbody>
                @forelse($latestRouteFailures as $call)
                    <tr><td>{{ $call->started_at?->copy()->timezone(config('app.display_timezone'))->format('d/m H:i:s') }}</td><td>{{ $call->tenant?->name ?? '—' }}</td><td>{{ $call->extension?->number ?? '—' }}</td><td>{{ $call->trunk?->name ?? 'Não identificada' }}</td><td><code>{{ $call->dialed_uri ?: 'Aguardando evento AMI' }}</code></td><td>{{ $call->hangup_cause ?: 'Sem detalhe' }}</td></tr>
                @empty
                    <tr><td colspan="6" class="empty-cell">Nenhuma falha registrada nas últimas 24 horas.</td></tr>
                @endforelse
            </tbody></table></div>
        </section>

        <section class="registry tenant-list" id="usuarios-ramais">
            <div class="section-title"><div><p class="eyebrow">EMPRESAS E RAMAIS</p><h2>Configuração por empresa</h2></div></div>
            @forelse($tenants as $tenant)
                <details class="panel tenant-card">
                    <summary><span class="tenant-initial">{{ mb_strtoupper(mb_substr($tenant->name, 0, 2)) }}</span><span><strong>{{ $tenant->name }}</strong><small>{{ $tenant->extensions->count() }} ramais · {{ $tenant->phoneLicenseLeases->count() }}/{{ $tenant->concurrent_agent_limit }} licenças em uso · retenção {{ $tenant->recording_retention_days ?: 'sem expiração' }}{{ $tenant->recording_retention_days ? ' dias' : '' }}</small></span><span class="tenant-status {{ $tenant->status }}">{{ $tenant->status === 'active' ? 'Ativa' : 'Inativa' }}</span></summary>
                    <div class="tenant-detail-grid">
                        <details class="crud-full"><summary class="button button-soft">Editar empresa</summary><div class="crud-editor"><form method="POST" action="{{ route('admin.tenants.update', $tenant) }}" class="stack-form">@csrf @method('PUT')<div class="form-pair"><label>Nome<input name="name" value="{{ $tenant->name }}" required></label><label>Identificador<input name="slug" value="{{ $tenant->slug }}" required></label></div><div class="form-pair"><label>Licenças simultâneas<input name="concurrent_agent_limit" type="number" min="0" max="10000" value="{{ $tenant->concurrent_agent_limit }}" required><small>{{ $tenant->phoneLicenseLeases->count() }} em uso. Não é possível reduzir abaixo desse total.</small></label><label>Status<select name="status"><option value="active" @selected($tenant->status === 'active')>Ativa</option><option value="inactive" @selected($tenant->status === 'inactive')>Inativa</option></select></label></div><label>Retenção<select name="recording_retention_days">@foreach([30,60,90,180,365,0] as $days)<option value="{{ $days }}" @selected((int) $tenant->recording_retention_days === $days)>{{ $days ? $days.' dias' : 'Sem expiração' }}</option>@endforeach</select></label><label class="check"><input type="checkbox" name="record_calls" value="1" @checked($tenant->record_calls)><span>Gravar chamadas</span></label><button class="button button-primary">Salvar empresa</button></form><form method="POST" action="{{ route('admin.tenants.destroy', $tenant) }}" data-confirm-title="Excluir empresa?" data-confirm="A empresa, seus ramais e vínculos serão removidos. Esta ação não pode ser desfeita." data-confirm-label="Excluir empresa" data-confirm-tone="danger">@csrf @method('DELETE')<button class="button button-danger">Excluir empresa</button></form></div></details>
                        <form method="POST" action="{{ route('admin.tenants.trunks.store', $tenant) }}" class="inline-form">
                            @csrf
                            <label>Vincular rota<select name="sip_trunk_id" required><option value="">Selecione</option>@foreach($trunks as $trunk)<option value="{{ $trunk->id }}">{{ $trunk->name }}</option>@endforeach</select></label><label>Prioridade<input name="priority" type="number" min="1" value="100" required></label><button class="button button-soft" type="submit">Vincular</button>
                        </form>
                        <div class="tenant-routes"><p class="mini-label">ROTAS ATIVAS</p>@forelse($tenant->trunks as $trunk)<span>{{ $trunk->name }} <small>prioridade {{ $trunk->pivot->priority }}</small><form method="POST" action="{{ route('admin.tenants.trunks.destroy', [$tenant, $trunk]) }}" data-confirm-title="Desvincular rota?" data-confirm="A rota {{ $trunk->name }} deixará de atender esta empresa." data-confirm-label="Desvincular" data-confirm-tone="danger">@csrf @method('DELETE')<button class="text-danger">Desvincular</button></form></span>@empty<span class="muted">Nenhuma rota vinculada.</span>@endforelse</div>
                        <div class="extension-list license-list"><p class="mini-label">LICENÇAS DE TELEFONIA · {{ $tenant->phoneLicenseLeases->count() }}/{{ $tenant->concurrent_agent_limit }} EM USO</p>@forelse($tenant->phoneLicenseLeases as $lease)<span><b>{{ $lease->user?->name ?? 'Agente removido' }}</b> <small>ramal {{ $lease->extension?->number ?? '—' }}</small><form method="POST" action="{{ route('admin.tenants.licenses.logout', [$tenant, $lease]) }}" data-confirm-title="Deslogar agente?" data-confirm="A sessão de {{ $lease->user?->name ?? 'este agente' }} será encerrada e a licença será liberada." data-confirm-label="Deslogar agente" data-confirm-tone="danger">@csrf<button class="text-danger">Deslogar</button></form></span>@empty<span class="muted">Nenhuma licença em uso.</span>@endforelse</div>
                        <section class="extension-list" data-tenant-user-panel data-tenant-id="{{ $tenant->id }}">@include('admin.partials.tenant-users-content', ['tenant' => $tenant])</section>
                        <details class="crud-full tenant-pause-settings"><summary class="button button-soft">Configurar pausas</summary><div class="crud-editor"><div class="async-feedback" data-async-feedback role="status" aria-live="polite" hidden></div>
                            <div class="tenant-pause-layout">
                                <form method="POST" action="{{ route('admin.pauses.store') }}" class="tenant-pause-create" data-async-form="pauses">@csrf<input type="hidden" name="tenant_id" value="{{ $tenant->id }}"><label>Nome da pausa<input name="name" maxlength="80" placeholder="Ex.: Banheiro" required></label><label>Cor<input name="color" type="color" value="#f4b000" required></label><label>Limite (min)<input name="max_minutes" type="number" min="1" max="480" placeholder="Sem limite"></label><button class="button button-primary">Cadastrar pausa</button><div class="async-feedback" data-async-feedback role="status" aria-live="polite" hidden></div></form>
                                <div class="tenant-pause-list">@include('admin.partials.pause-list', ['pauses' => $tenant->pauseReasons])</div>
                            </div>
                        </div></details>
                    </div>
                </details>
            @empty
                <div class="panel empty-state">Crie a primeira empresa para começar a distribuir ramais.</div>
            @endforelse
        </section>
    </main>
</div>
@endsection
