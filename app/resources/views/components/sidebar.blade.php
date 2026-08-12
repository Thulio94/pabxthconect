<aside class="sidebar">
    <a href="{{ auth()->user()?->isSuperAdmin() ? route('admin.index') : route('admin.supervision.index') }}" class="brand-mark" aria-label="Administração Thconect"><img src="{{ asset('brand/thconect-primary.png') }}" alt=""></a>
    <nav class="side-nav" aria-label="Navegação principal">
        <a href="{{ route('admin.supervision.index') }}" class="side-link {{ request()->routeIs('admin.supervision.*') ? 'active' : '' }}" title="Acompanhamento"><span aria-hidden="true">◉</span></a>
        <a href="{{ route('admin.recordings.index') }}" class="side-link {{ request()->routeIs('admin.recordings.*') ? 'active' : '' }}" title="Gravações"><span aria-hidden="true">♪</span></a>
        @if(auth()->user()?->isSuperAdmin())
            <a href="{{ route('admin.index') }}" class="side-link {{ request()->routeIs('admin.index') ? 'active' : '' }}" title="Configurações"><span aria-hidden="true">⚙</span></a>
        @endif
        <a href="{{ route('phone.login') }}" class="side-link" title="Abrir telefone"><span aria-hidden="true">☎</span></a>
    </nav>
    <div class="side-bottom"><span class="avatar">SA</span><form method="POST" action="{{ route('logout') }}" class="logout">@csrf<button class="side-link" title="Sair" type="submit"><span aria-hidden="true">↪</span></button></form></div>
</aside>
