<div class="user-manager-head">
    <div><p class="mini-label">USUÁRIOS E RAMAIS</p><p class="muted">O login é o e-mail. O ramal é escolhido automaticamente dentro da faixa desta empresa.</p></div>
    <span class="user-count" data-user-count>{{ $tenant->extensions->count() }} cadastrados</span>
</div>
<div class="async-feedback" data-async-feedback role="status" aria-live="polite" hidden></div>

<form method="POST" action="{{ route('admin.tenants.users.store', $tenant) }}" class="bulk-user-form" data-async-form="users">
    @csrf
    <div class="bulk-user-heading"><div><h3>Adicionar usuários</h3><p class="muted">Crie um ou vários ramais na mesma operação. Senhas geradas aparecem uma única vez ao concluir.</p></div><button class="button button-soft" type="button" data-add-user>＋ Adicionar linha</button></div>
    <div class="bulk-user-rows" data-user-rows>
        <fieldset class="bulk-user-row" data-user-row>
            <legend>Usuário <span data-row-number>1</span></legend>
            <label>Nome<input name="users[0][name]" maxlength="120" autocomplete="name" required></label>
            <label>E-mail de acesso<input name="users[0][email]" type="email" maxlength="255" autocomplete="email" required></label>
            <label>Perfil<select name="users[0][role]"><option value="agent">Agente</option><option value="tenant_admin">Administrador da empresa</option></select></label>
            <button class="remove-user-row" type="button" data-remove-user aria-label="Remover usuário" disabled>×</button>
        </fieldset>
    </div>
    <template data-user-row-template>
        <fieldset class="bulk-user-row" data-user-row>
            <legend>Usuário <span data-row-number></span></legend>
            <label>Nome<input data-field="name" maxlength="120" autocomplete="name" required></label>
            <label>E-mail de acesso<input data-field="email" type="email" maxlength="255" autocomplete="email" required></label>
            <label>Perfil<select data-field="role"><option value="agent">Agente</option><option value="tenant_admin">Administrador da empresa</option></select></label>
            <button class="remove-user-row" type="button" data-remove-user aria-label="Remover usuário">×</button>
        </fieldset>
    </template>
    <button class="button button-primary" type="submit">Criar usuários e ramais</button>
</form>

<section class="credential-results" data-credential-results hidden aria-live="polite">
    <div class="credential-results-heading"><div><p class="mini-label">GUARDE AGORA</p><h3>Credenciais geradas</h3><p class="muted">Por segurança, as senhas só são exibidas nesta confirmação. Exporte ou copie antes de fechar.</p></div><div class="credential-export"><button class="button button-soft" type="button" data-export-credentials="txt">Baixar TXT</button><button class="button button-soft" type="button" data-export-credentials="csv">Baixar CSV</button></div></div>
    <div class="table-wrap"><table><thead><tr><th>Nome</th><th>Login</th><th>Ramal</th><th>Perfil</th><th>Senha inicial</th></tr></thead><tbody data-credential-rows></tbody></table></div>
</section>

<div class="tenant-user-list">
    <div class="tenant-user-list-heading"><h3>Usuários cadastrados</h3><span>{{ $tenant->extensions->count() }}</span></div>
    @forelse($tenant->extensions as $extension)
        <details class="tenant-user-item" data-extension-id="{{ $extension->id }}">
            <summary><span class="user-extension-number">{{ $extension->number }}</span><span class="tenant-user-identity"><b>{{ $extension->user?->name ?? 'Sem usuário' }}</b><small>{{ $extension->user?->email ?? 'Login não configurado' }}</small></span><span class="extension-state {{ $extension->status }}">{{ $extension->status === 'active' ? 'Ativo' : 'Desativado' }}</span><span class="user-row-edit">Editar⌄</span></summary>
            <div class="tenant-user-editor">
                <form method="POST" action="{{ route('admin.extensions.update', $extension) }}" class="stack-form" data-async-form="users">
                    @csrf @method('PUT')
                    <div class="form-pair"><label>Nome<input name="name" value="{{ $extension->user?->name }}" required></label><label>E-mail<input name="email" type="email" value="{{ $extension->user?->email }}" required></label></div>
                    <div class="form-pair"><label>Ramal<input name="number" type="number" min="999" max="10000" value="{{ $extension->number }}" required></label><label>Perfil<select name="role">@if($extension->user?->isSuperAdmin())<option value="superadmin">Superadmin</option>@else<option value="agent" @selected($extension->user?->role === 'agent')>Agente</option><option value="tenant_admin" @selected($extension->user?->role === 'tenant_admin')>Administrador da empresa</option>@endif</select></label></div>
                    <div class="form-pair"><label>Status<select name="status"><option value="active" @selected($extension->status === 'active')>Ativo</option><option value="disabled" @selected($extension->status === 'disabled')>Desativado</option></select></label><label class="check"><input type="checkbox" name="rotate_secret" value="1"><span>Gerar nova senha SIP</span></label></div>
                    <button class="button button-primary">Salvar alterações</button>
                </form>
                @unless($extension->user?->isSuperAdmin())
                    <form method="POST" action="{{ route('admin.extensions.destroy', $extension) }}" data-async-form="users" data-confirm-title="Excluir usuário e ramal?" data-confirm="O acesso de {{ $extension->user?->name ?? 'este usuário' }} e o ramal {{ $extension->number }} serão removidos." data-confirm-label="Excluir usuário" data-confirm-tone="danger">@csrf @method('DELETE')<button class="button button-danger">Excluir usuário e ramal</button></form>
                @endunless
            </div>
        </details>
    @empty
        <div class="empty-cell">Ainda não há usuários nesta empresa. Adicione-os pelo formulário acima.</div>
    @endforelse
</div>
