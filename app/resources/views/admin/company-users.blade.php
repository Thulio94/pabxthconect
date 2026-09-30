@extends('layouts.app', ['title' => 'Usuários da empresa | Thconect'])

@section('body')
<div class="app-shell"><x-sidebar />
    <main class="workspace admin-workspace company-users-workspace">
        <header class="page-heading"><div><p class="eyebrow">{{ $tenant->name }}</p><h1>Usuários da empresa</h1></div><a class="button button-soft" href="{{ route('admin.supervision.index') }}">Voltar ao acompanhamento</a></header>
        <section class="panel company-user-create" data-company-user-panel>
            <div class="section-title"><div><p class="eyebrow">ACESSO DA EQUIPE</p><h2>Criar agente ou supervisor</h2><p class="muted">O agente recebe um ramal. O supervisor recebe acesso somente ao acompanhamento e às gravações dos agentes desta empresa.</p></div></div>
            <div class="async-feedback" data-company-user-feedback role="status" aria-live="polite" hidden></div>
            <form method="POST" action="{{ route('admin.company-users.store') }}" class="stack-form company-user-form" data-company-user-form>
                @csrf
                <label>Nome<input name="name" maxlength="120" autocomplete="name" required></label>
                <label>E-mail de acesso<input name="email" type="email" maxlength="255" autocomplete="email" required></label>
                <label>Perfil<select name="role" required><option value="agent">Agente</option><option value="supervisor">Supervisor</option></select></label>
                <button class="button button-primary" type="submit">Criar usuário</button>
            </form>
            <section class="credential-results company-user-credentials" data-company-user-credentials hidden aria-live="polite">
                <div class="credential-results-heading"><div><p class="mini-label">GUARDE AGORA</p><h3>Credencial inicial</h3><p class="muted">A senha é mostrada somente nesta confirmação. Entregue-a ao usuário por um canal seguro.</p></div></div>
                <div class="table-wrap"><table><thead><tr><th>Nome</th><th>Login</th><th>Ramal</th><th>Perfil</th><th>Senha inicial</th></tr></thead><tbody data-company-user-credential></tbody></table></div>
            </section>
        </section>
    </main>
</div>
@endsection
