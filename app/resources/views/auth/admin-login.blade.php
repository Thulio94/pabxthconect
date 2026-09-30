@extends('layouts.app', ['title' => 'Acesso administrativo | Thconect'])

@section('body')
<main class="auth-shell single-panel admin-auth">
    <section class="auth-card-wrap">
        <form method="POST" action="{{ route('login.store') }}" class="auth-card">
            @csrf
            <p class="eyebrow">ÁREA ADMINISTRATIVA</p>
            <h2>Acesso administrativo</h2>
            <p class="muted">Acesse o acompanhamento e as ferramentas autorizadas para o seu perfil.</p>
            @if ($errors->any())
                <div class="alert alert-error">{{ $errors->first() }}</div>
            @endif
            <label>Login
                <input name="username" value="{{ old('username') }}" autocomplete="username" required autofocus>
            </label>
            <label>Senha
                <input name="password" type="password" autocomplete="current-password" required>
            </label>
            <label class="check"><input type="checkbox" name="remember"> <span>Manter acesso neste dispositivo</span></label>
            <button class="button button-primary button-full" type="submit">Entrar na administração</button>
            <p class="auth-footnote"><a href="{{ route('phone.login') }}">Voltar ao telefone</a></p>
        </form>
    </section>
</main>
@endsection
