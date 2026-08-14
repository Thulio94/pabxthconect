<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Tela do Agente - Thconect' }}</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon-96x96.png') }}" sizes="96x96">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}" sizes="180x180">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @yield('body')
    <div class="system-confirm" id="systemConfirm" hidden aria-hidden="true">
        <div class="system-confirm-backdrop" data-confirm-cancel aria-hidden="true"></div>
        <section class="system-confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="systemConfirmTitle" aria-describedby="systemConfirmMessage">
            <div class="system-confirm-signal" aria-hidden="true"><span></span><i></i></div>
            <div class="system-confirm-content">
                <p class="system-confirm-kicker">CONFIRMAÇÃO DA AÇÃO</p>
                <h2 id="systemConfirmTitle">Confirmar ação?</h2>
                <p id="systemConfirmMessage">Revise os dados antes de continuar.</p>
                <div class="system-confirm-actions">
                    <button type="button" class="system-confirm-cancel" data-confirm-cancel>Cancelar</button>
                    <button type="button" class="system-confirm-accept" data-confirm-accept>Confirmar</button>
                </div>
            </div>
        </section>
    </div>
</body>
</html>
