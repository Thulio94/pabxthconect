<aside class="sidebar" id="adminSidebar">
    <div class="sidebar-head">
        <a href="{{ auth()->user()?->isSuperAdmin() ? route('admin.index') : (auth()->user()?->isSupervisor() ? route('supervisor.dashboard') : route('admin.supervision.index')) }}" class="brand-mark" aria-label="Administração Thconect"><img src="{{ asset('brand/thconect-primary.png') }}" alt=""></a>
        <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Expandir menu" aria-expanded="false"><span></span><span></span><span></span></button>
    </div>
    <nav class="side-nav" aria-label="Navegação principal">
        @if(auth()->user()?->isSupervisor())
            <a href="{{ route('supervisor.dashboard') }}" class="side-link {{ request()->routeIs('supervisor.dashboard', 'supervisor.agents') ? 'active' : '' }}" title="Acompanhamento"><span class="side-icon" aria-hidden="true">◉</span><span class="side-label">Acompanhamento</span></a>
            <a href="{{ route('supervisor.recordings.index') }}" class="side-link {{ request()->routeIs('supervisor.recordings.*') ? 'active' : '' }}" title="Gravações"><span class="side-icon" aria-hidden="true">♫</span><span class="side-label">Gravações</span></a>
        @else
            <a href="{{ route('admin.supervision.index') }}" class="side-link {{ request()->routeIs('admin.supervision.*') ? 'active' : '' }}" title="Acompanhamento"><span class="side-icon" aria-hidden="true">◉</span><span class="side-label">Acompanhamento</span></a>
            <a href="{{ route('admin.recordings.index') }}" class="side-link {{ request()->routeIs('admin.recordings.*') ? 'active' : '' }}" title="Gravações"><span class="side-icon" aria-hidden="true">♫</span><span class="side-label">Gravações</span></a>
        @endif
        @if(auth()->user()?->isTenantAdmin())
            <a href="{{ route('admin.company-users.index') }}" class="side-link {{ request()->routeIs('admin.company-users.*') ? 'active' : '' }}" title="Usuários da empresa"><span class="side-icon" aria-hidden="true">♙</span><span class="side-label">Usuários da empresa</span></a>
        @endif
        @if(auth()->user()?->isSuperAdmin())
            <div class="side-menu {{ request()->routeIs('admin.index') ? 'open' : '' }}">
                <button class="side-link side-menu-trigger {{ request()->routeIs('admin.index') ? 'active' : '' }}" type="button" title="Configurações" aria-expanded="{{ request()->routeIs('admin.index') ? 'true' : 'false' }}">
                    <span class="side-icon" aria-hidden="true">⚙</span><span class="side-label">Configurações</span><span class="side-chevron" aria-hidden="true">›</span>
                </button>
                <div class="side-submenu">
                    <a href="{{ route('admin.index') }}#visao-geral">Visão geral</a>
                    <a href="{{ route('admin.index') }}#nova-rota">Rotas SIP</a>
                    <a href="{{ route('admin.index') }}#nova-empresa">Empresas</a>
                    <a href="{{ route('admin.index') }}#usuarios-ramais">Usuários e ramais</a>
                    <a href="{{ route('admin.index') }}#diagnostico">Diagnóstico</a>
                </div>
            </div>
        @endif
        @if(auth()->user()?->isSuperAdmin())<a href="{{ route('phone.login') }}" class="side-link" title="Abrir telefone"><span class="side-icon" aria-hidden="true">☎</span><span class="side-label">Abrir telefone</span></a>@endif
    </nav>
    <div class="side-bottom">
        <span class="avatar">{{ auth()->user()?->isSuperAdmin() ? 'SA' : (auth()->user()?->isSupervisor() ? 'SU' : 'AD') }}</span>
        <span class="side-user"><b>{{ auth()->user()?->name }}</b><small>{{ auth()->user()?->email }}</small></span>
        <form method="POST" action="{{ route('logout') }}" class="logout">@csrf<button class="side-link" title="Sair" type="submit"><span class="side-icon" aria-hidden="true">↪</span><span class="side-label">Sair</span></button></form>
    </div>
</aside>
