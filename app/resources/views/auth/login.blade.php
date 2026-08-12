@extends('layouts.app', ['title' => 'Entrar | Tela do Agente - Thconect'])

@section('body')
<main class="auth-shell">
    <section class="auth-brand" aria-label="Thconect">
        <img src="{{ asset('brand/thconect-primary.png') }}" alt="Thconect">
        <div>
            <p class="eyebrow">SEU RAMAL, EM QUALQUER LUGAR</p>
            <h1>Uma linha direta<br>no navegador.</h1>
            <p>Use o ramal criado na Central PBX para fazer chamadas sem instalar um softphone.</p>
        </div>
    </section>
    <section class="auth-card-wrap">
        <form method="POST" action="{{ route('phone.login.store') }}" class="auth-card">
            @csrf
            <p class="eyebrow">TELEFONE WEB</p>
            <h2>Entrar no telefone</h2>
            <p class="muted">Informe seu e-mail e a senha entregues pelo administrador.</p>
            @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
            @if ($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif
            <label>E-mail<input name="email" type="email" value="{{ old('email') }}" placeholder="nome@empresa.com.br" autocomplete="username" required autofocus></label>
            <label>Senha do ramal<input name="password" type="password" autocomplete="current-password" required></label>
            <button class="button button-primary button-full" type="submit">Entrar com segurança</button>
        </form>
    </section>
</main>
@endsection
