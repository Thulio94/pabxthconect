@extends('layouts.app', ['title' => 'Criar nova senha | Tela do Agente - Thconect'])

@section('body')
<main class="auth-shell single-panel">
    <section class="auth-card-wrap">
        <form method="POST" action="{{ route('password.change.update') }}" class="auth-card">
            @csrf
            @method('PUT')
            <p class="eyebrow">PRIMEIRO ACESSO</p>
            <h2>Crie sua nova senha</h2>
            <p class="muted">Sua senha temporária não poderá mais ser usada após esta etapa.</p>
            @if ($errors->any())
                <div class="alert alert-error">{{ $errors->first() }}</div>
            @endif
            <label>Nova senha
                <input name="password" type="password" autocomplete="new-password" required autofocus>
            </label>
            <label>Confirmar nova senha
                <input name="password_confirmation" type="password" autocomplete="new-password" required>
            </label>
            <p class="password-hint">Use ao menos 12 caracteres, com letras maiúsculas, minúsculas, números e símbolo.</p>
            <button class="button button-primary button-full" type="submit">Salvar nova senha</button>
        </form>
    </section>
</main>
@endsection
