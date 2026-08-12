<aside class="sidebar">
    <a href="{{ route('admin.index') }}" class="brand-mark" aria-label="Administração Thconect">
        <img src="{{ asset('brand/thconect-primary.png') }}" alt="">
    </a>
    <nav class="side-nav" aria-label="Navegação principal">
        <a href="{{ route('admin.recordings.index') }}" class="side-link" title="Gravações"><span aria-hidden="true">♪</span></a>
        <a href="{{ route('admin.index') }}" class="side-link active" title="Servidores"><span aria-hidden="true">⚙</span></a>
        <a href="{{ route('phone.login') }}" class="side-link" title="Abrir telefone"><span aria-hidden="true">☎</span></a>
    </nav>
    <div class="side-bottom">
        <span class="avatar">SA</span>
        <form method="POST" action="{{ route('logout') }}" class="logout">@csrf
            <button class="side-link" title="Sair" type="submit"><span aria-hidden="true">↪</span></button>
        </form>
    </div>
</aside>
