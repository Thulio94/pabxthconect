# Thconect Phone

Central PBX SaaS multiempresa em Laravel, Asterisk e WebRTC. O sistema provisiona os próprios usuários e ramais, envia chamadas por rotas SIP compartilháveis e mantém histórico e gravações por empresa.

## Fluxo

1. O superadmin cadastra uma ou mais rotas SIP.
2. Cria a empresa e vincula suas rotas de saída por prioridade.
3. Cria o usuário; o sistema reserva automaticamente o próximo ramal livre da empresa e gera uma senha de oito caracteres.
4. Todos, inclusive o superadmin, entram em `http://localhost:8080/entrar` usando e-mail e senha.
5. O e-mail identifica globalmente o usuário, a empresa e seu ramal sem exibir empresas na página pública.

## Regras de identidade

- O e-mail é único globalmente, sem diferenciar maiúsculas e minúsculas.
- O número do ramal é único somente dentro da empresa. Empresas diferentes podem usar o mesmo ramal.
- O usuário SIP técnico permanece globalmente único no formato `t{empresa}-e{ramal}`.
- A senha gerada autentica o sistema e o ramal. Ela é armazenada como hash no usuário e criptografada no cadastro SIP.
- O superadmin também possui empresa e ramal, entra pela tela comum e acessa a administração pelo atalho interno.

## Funcionalidades

- Chamadas WebRTC de entrada e saída, mudo, espera e encerramento.
- Rotas SIP por TECH/IP ou usuário e senha, compartilháveis entre empresas.
- Campo de telefone validado e limitado a DDD mais dez ou onze dígitos.
- Histórico isolado por empresa e ramal, filtros e rolagem incremental.
- Gravação automática no Asterisk e painel administrativo de reprodução.
- CRUD de empresas, rotas, vínculos, usuários e ramais.
- Console de áudio com seleção, volume, mudo e testes de microfone e saída.

## Docker Desktop

```powershell
Copy-Item .env.example .env
docker compose up -d --build
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan pbx:provision
```

Em uma instalação vazia, crie o superadmin depois das migrations:

```powershell
docker compose exec app php artisan app:bootstrap-superadmin --email=superadmin@local.test
```

- Aplicação e login único: `http://localhost:8080/entrar`
- Mailpit: `http://localhost:8025`

## Testes

```powershell
docker compose exec -T app run-tests
docker compose run --rm assets
```

`run-tests` força SQLite em memória e impede que `RefreshDatabase` altere o PostgreSQL de desenvolvimento.

## Produção

WebRTC exige HTTPS e WSS. Antes de publicar:

- copie `.env.example` para `.env` e troque `DB_PASSWORD` e `PBX_AMI_SECRET`;
- configure `APP_URL`, `PBX_SIP_DOMAIN` e `PBX_WEBSOCKET_URL` com o domínio HTTPS/WSS real;
- gere uma `APP_KEY` no arquivo privado `app/.env` com `php artisan key:generate`;
- configure certificados, cookies `Secure`, CSP, HSTS, backup do PostgreSQL e do volume de gravações;
- libere no firewall somente as portas SIP/RTP e web realmente necessárias;
- defina a política legal de retenção das chamadas.

Arquivos de provisionamento SIP, credenciais AMI geradas, banco local, `.env` e gravações são ignorados pelo Git.
