<aside class="sidebar phone-sidebar">
    <a href="{{ route('phone.dashboard') }}" class="brand-mark" aria-label="Thconect Phone">
        <img src="{{ asset('brand/thconect-primary.png') }}" alt="">
    </a>
    <nav class="side-nav" aria-label="Navegação do telefone">
        <a href="{{ route('phone.dashboard') }}" class="side-link active" title="Telefone"><span aria-hidden="true">☎</span></a>
        <a href="#history" class="side-link" title="Histórico"><span aria-hidden="true">◷</span></a>
        <a href="#appointments" class="side-link" title="Agenda"><span aria-hidden="true">◴</span></a>
        @if(auth()->user()?->canManageOperation())
            <a href="{{ auth()->user()->isSuperAdmin() ? route('admin.index') : route('admin.supervision.index') }}" class="side-link" title="Administração"><span aria-hidden="true">⚙</span></a>
        @endif
    </nav>
    <div class="side-bottom">
        <span class="avatar">{{ mb_strtoupper(mb_substr($extension, 0, 2)) }}</span>
        <form method="POST" action="{{ route('phone.logout') }}" class="logout" id="phoneLogout">@csrf
            <button class="side-link" title="Desconectar ramal" type="submit"><span aria-hidden="true">↪</span></button>
        </form>
    </div>
</aside>
