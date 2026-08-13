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
                    <label>Retenção das gravações<select name="recording_retention_days"><option value="30">30 dias</option><option value="60">60 dias</option><option value="90" selected>90 dias</option><option value="180">180 dias</option><option value="365">365 dias</option><option value="0">Sem expiração automática</option></select></label>
                    <label class="check"><input type="checkbox" name="record_calls" value="1" checked><span>Gravar chamadas realizadas</span></label>
                    <button class="button button-primary" type="submit">Criar empresa</button>
                </form>
            </section>

            <section class="panel">
                <p class="eyebrow">03 · USUÁRIO E RAMAL</p><h2>Criar credencial</h2>
                <p class="muted">O e-mail é o login global. O sistema escolhe o próximo ramal livre de 999 a 10000 dentro da empresa.</p>
                <details class="create-user-disclosure" @if($errors->has('user')) open @endif>
                    <summary class="button button-primary">Criar usuário e ramal</summary>
                    <form method="POST" action="{{ route('admin.users.store') }}" class="stack-form">
                        @csrf
                        <label>Empresa<select name="tenant_id" required><option value="">Selecione</option>@foreach($tenants as $tenant)<option value="{{ $tenant->id }}">{{ $tenant->name }}</option>@endforeach</select></label>
                        <label>Nome<input name="name" required></label>
                        <div class="form-pair"><label>E-mail<input name="email" type="email" required></label><label>Perfil<select name="role"><option value="agent">Agente</option><option value="tenant_admin">Administrador da empresa</option></select></label></div>
                        <button class="button button-primary" type="submit">Gerar credencial do ramal</button>
                    </form>
                </details>
            </section>
        </div>

        <section class="registry pbx-registry">
            <div class="section-title"><div><p class="eyebrow">ROTAS CADASTRADAS</p><h2>Saída do PBX</h2></div></div>
            <div class="route-list">
                @forelse($trunks as $trunk)
                    <details class="route-row"><summary><span class="route-glyph">↗</span><div><strong>{{ $trunk->name }}</strong><small>{{ $trunk->auth_mode === 'ip_tech' ? 'TECH/IP autorizado · TECH '.$trunk->tech_prefix : 'Usuário e senha protegidos' }}</small></div><code>{{ $trunk->host }}:{{ $trunk->port }}</code><span class="route-use">{{ $trunk->tenants_count }} empresas</span><span class="button button-soft">Gerenciar</span></summary><div class="crud-editor"><form method="POST" action="{{ route('admin.trunks.update', $trunk) }}" class="stack-form">@csrf @method('PUT')<div class="form-pair"><label>Nome<input name="name" value="{{ $trunk->name }}" required></label><label>Modo<select name="auth_mode"><option value="ip_tech" @selected($trunk->auth_mode === 'ip_tech')>TECH/IP</option><option value="userpass" @selected($trunk->auth_mode === 'userpass')>Usuário/senha</option></select></label></div><div class="form-pair"><label>Host<input name="host" value="{{ $trunk->host }}" required></label><label>Porta<input name="port" type="number" value="{{ $trunk->port }}" required></label></div><div class="form-pair"><label>Transporte<select name="transport"><option value="udp" @selected($trunk->transport === 'udp')>UDP</option><option value="tcp" @selected($trunk->transport === 'tcp')>TCP</option><option value="tls" @selected($trunk->transport === 'tls')>TLS</option></select></label><label>TECH<input name="tech_prefix" value="{{ $trunk->tech_prefix }}"></label></div><div class="form-pair"><label>Usuário SIP<input name="username" value="{{ $trunk->username }}"></label><label>Nova senha <span class="optional">vazio mantém</span><input name="password" type="password"></label></div><label class="check"><input type="checkbox" name="is_active" value="1" @checked($trunk->is_active)><span>Rota ativa</span></label><div class="crud-actions"><button class="button button-primary">Salvar rota</button></div></form><div class="crud-actions"><form method="POST" action="{{ route('admin.trunks.test', $trunk) }}">@csrf<button class="button button-soft">Testar rota</button></form><form method="POST" action="{{ route('admin.trunks.destroy', $trunk) }}" onsubmit="return confirm('Excluir esta rota e todos os vínculos?')">@csrf @method('DELETE')<button class="button button-danger">Excluir rota</button></form></div></div></details>
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

        <section class="registry tenant-list">
            <div class="section-title"><div><p class="eyebrow">EMPRESAS E RAMAIS</p><h2>Configuração por empresa</h2></div></div>
            @forelse($tenants as $tenant)
                <details class="panel tenant-card">
                    <summary><span class="tenant-initial">{{ mb_strtoupper(mb_substr($tenant->name, 0, 2)) }}</span><span><strong>{{ $tenant->name }}</strong><small>{{ $tenant->extensions->count() }} ramais · retenção {{ $tenant->recording_retention_days ?: 'sem expiração' }}{{ $tenant->recording_retention_days ? ' dias' : '' }}</small></span><span class="tenant-status {{ $tenant->status }}">{{ $tenant->status === 'active' ? 'Ativa' : 'Inativa' }}</span></summary>
                    <div class="tenant-detail-grid">
                        <details class="crud-full"><summary class="button button-soft">Editar empresa</summary><div class="crud-editor"><form method="POST" action="{{ route('admin.tenants.update', $tenant) }}" class="stack-form">@csrf @method('PUT')<div class="form-pair"><label>Nome<input name="name" value="{{ $tenant->name }}" required></label><label>Identificador<input name="slug" value="{{ $tenant->slug }}" required></label></div><div class="form-pair"><label>Retenção<select name="recording_retention_days">@foreach([30,60,90,180,365,0] as $days)<option value="{{ $days }}" @selected((int) $tenant->recording_retention_days === $days)>{{ $days ? $days.' dias' : 'Sem expiração' }}</option>@endforeach</select></label><label>Status<select name="status"><option value="active" @selected($tenant->status === 'active')>Ativa</option><option value="inactive" @selected($tenant->status === 'inactive')>Inativa</option></select></label></div><label class="check"><input type="checkbox" name="record_calls" value="1" @checked($tenant->record_calls)><span>Gravar chamadas</span></label><button class="button button-primary">Salvar empresa</button></form><form method="POST" action="{{ route('admin.tenants.destroy', $tenant) }}" onsubmit="return confirm('Excluir empresa, ramais e vínculos?')">@csrf @method('DELETE')<button class="button button-danger">Excluir empresa</button></form></div></details>
                        <form method="POST" action="{{ route('admin.tenants.trunks.store', $tenant) }}" class="inline-form">
                            @csrf
                            <label>Vincular rota<select name="sip_trunk_id" required><option value="">Selecione</option>@foreach($trunks as $trunk)<option value="{{ $trunk->id }}">{{ $trunk->name }}</option>@endforeach</select></label><label>Prioridade<input name="priority" type="number" min="1" value="100" required></label><button class="button button-soft" type="submit">Vincular</button>
                        </form>
                        <div class="tenant-routes"><p class="mini-label">ROTAS ATIVAS</p>@forelse($tenant->trunks as $trunk)<span>{{ $trunk->name }} <small>prioridade {{ $trunk->pivot->priority }}</small><form method="POST" action="{{ route('admin.tenants.trunks.destroy', [$tenant, $trunk]) }}">@csrf @method('DELETE')<button class="text-danger">Desvincular</button></form></span>@empty<span class="muted">Nenhuma rota vinculada.</span>@endforelse</div>
                        <div class="extension-list"><p class="mini-label">RAMAIS</p>@forelse($tenant->extensions as $extension)<details><summary><b>{{ $extension->number }}</b> {{ $extension->user?->name ?? 'Sem usuário' }} <small>{{ $extension->status }}</small></summary><div class="crud-editor"><form method="POST" action="{{ route('admin.extensions.update', $extension) }}" class="stack-form">@csrf @method('PUT')<label>Nome<input name="name" value="{{ $extension->user?->name }}" required></label><label>E-mail<input name="email" type="email" value="{{ $extension->user?->email }}" required></label><div class="form-pair"><label>Ramal<input name="number" type="number" min="999" max="10000" value="{{ $extension->number }}" required></label><label>Perfil<select name="role">@if($extension->user?->isSuperAdmin())<option value="superadmin">Superadmin</option>@else<option value="agent" @selected($extension->user?->role === 'agent')>Agente</option><option value="tenant_admin" @selected($extension->user?->role === 'tenant_admin')>Admin empresa</option>@endif</select></label></div><div class="form-pair"><label>Status<select name="status"><option value="active" @selected($extension->status === 'active')>Ativo</option><option value="disabled" @selected($extension->status === 'disabled')>Desativado</option></select></label><label class="check"><input type="checkbox" name="rotate_secret" value="1"><span>Gerar nova senha SIP</span></label></div><button class="button button-primary">Salvar ramal</button></form>@unless($extension->user?->isSuperAdmin())<form method="POST" action="{{ route('admin.extensions.destroy', $extension) }}" onsubmit="return confirm('Excluir usuário e ramal?')">@csrf @method('DELETE')<button class="button button-danger">Excluir usuário e ramal</button></form>@endunless</div></details>@empty<span class="muted">Nenhum ramal criado.</span>@endforelse</div>
                        <details class="crud-full tenant-pause-settings"><summary class="button button-soft">Configurar pausas</summary><div class="crud-editor">
                            <div class="tenant-pause-layout">
                                <form method="POST" action="{{ route('admin.pauses.store') }}" class="tenant-pause-create">@csrf<input type="hidden" name="tenant_id" value="{{ $tenant->id }}"><label>Nome da pausa<input name="name" maxlength="80" placeholder="Ex.: Banheiro" required></label><label>Cor<input name="color" type="color" value="#f4b000" required></label><label>Limite (min)<input name="max_minutes" type="number" min="1" max="480" placeholder="Sem limite"></label><button class="button button-primary">Cadastrar pausa</button></form>
                                <div class="tenant-pause-list">
                                    @forelse($tenant->pauseReasons as $pause)
                                    <details class="pause-item"><summary><i style="background:{{ $pause->color }}"></i><span><b>{{ $pause->name }}</b><small>{{ $pause->max_minutes ? $pause->max_minutes.' min' : 'sem limite' }}</small></span><em>{{ $pause->is_active ? 'Ativa' : 'Inativa' }}</em></summary><div class="pause-editor"><form method="POST" action="{{ route('admin.pauses.update', $pause) }}">@csrf @method('PUT')<label>Nome<input name="name" value="{{ $pause->name }}" required></label><label>Cor<input name="color" type="color" value="{{ $pause->color }}" required></label><label>Limite<input name="max_minutes" type="number" min="1" max="480" value="{{ $pause->max_minutes }}"></label><label class="check"><input type="checkbox" name="is_active" value="1" @checked($pause->is_active)><span>Ativa</span></label><button class="button button-primary">Salvar</button></form><form method="POST" action="{{ route('admin.pauses.destroy', $pause) }}" onsubmit="return confirm('Excluir esta pausa?')">@csrf @method('DELETE')<button class="button button-danger">Excluir</button></form></div></details>
                                    @empty<div class="empty-cell">Nenhuma pausa cadastrada para esta empresa.</div>@endforelse
                                </div>
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
