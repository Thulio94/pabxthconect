@forelse($users as $user)
    @php($extension = $user->pbxExtension)
    <details class="company-user-item" data-company-user-id="{{ $user->id }}">
        <summary>
            <span class="user-extension-number">{{ $extension?->number ?? 'SV' }}</span>
            <span class="tenant-user-identity"><b>{{ $user->name }}</b><small>{{ $user->email }}</small></span>
            <span class="extension-state {{ $extension?->status === 'active' ? 'active' : 'disabled' }}">{{ $user->isSupervisor() ? 'Supervisor' : ($extension?->status === 'active' ? 'Ativo' : 'Desativado') }}</span>
            <span class="user-row-edit">Gerenciar⌄</span>
        </summary>
        <div class="tenant-user-editor company-user-editor">
            <form method="POST" action="{{ route('admin.company-users.update', $user) }}" class="stack-form" data-company-user-action>
                @csrf
                @method('PUT')
                <div class="form-pair">
                    <label>Nome<input name="name" maxlength="120" value="{{ $user->name }}" autocomplete="name" required></label>
                    <label>E-mail de acesso<input name="email" type="email" maxlength="255" value="{{ $user->email }}" autocomplete="email" required></label>
                </div>
                <div class="form-pair">
                    <label>Perfil
                        <select name="role" data-company-user-role required>
                            <option value="agent" @selected($user->isAgent())>Agente</option>
                            <option value="supervisor" @selected($user->isSupervisor())>Supervisor</option>
                        </select>
                    </label>
                    <label data-agent-setting @if($user->isSupervisor()) hidden @endif>Ramal
                        <input name="number" type="number" min="{{ $tenant->extension_min }}" max="{{ $tenant->extension_max }}" value="{{ $extension?->number }}" data-agent-required @required($user->isAgent() && $extension)>
                    </label>
                </div>
                <label data-agent-setting @if($user->isSupervisor()) hidden @endif>Status do agente
                    <select name="status" data-agent-required @required($user->isAgent())>
                        <option value="active" @selected($extension?->status === 'active')>Ativo</option>
                        <option value="disabled" @selected($extension?->status !== 'active')>Desativado</option>
                    </select>
                </label>
                <label class="company-user-reset"><input type="checkbox" name="reset_password" value="1"> Redefinir senha e mostrar a nova credencial uma única vez</label>
                <div class="company-user-actions">
                    <button class="button button-primary" type="submit">Salvar usuário</button>
                </div>
            </form>
            <form method="POST" action="{{ route('admin.company-users.destroy', $user) }}" data-company-user-action data-company-user-confirm-title="Excluir usuário?" data-company-user-confirm="O acesso de {{ $user->name }} será removido da empresa. Essa ação não altera o limite de licenças." data-company-user-confirm-label="Excluir usuário">
                @csrf
                @method('DELETE')
                <button class="button button-danger" type="submit">Excluir usuário</button>
            </form>
        </div>
    </details>
@empty
    <p class="company-user-empty">Ainda não há agentes ou supervisores cadastrados nesta empresa.</p>
@endforelse
