# Produção no Easypanel

Esta pasta é isolada do ambiente local. O Easypanel deve usar `production/compose.yaml`; o `compose.yaml` da raiz continua destinado ao desenvolvimento.

## 1. Pré-requisitos

- Ubuntu novo com Easypanel instalado;
- IPv4 público fixo;
- DNS de `phone.seudominio.com.br` e `ws.seudominio.com.br` apontando para a VPS;
- portas `80/tcp`, `443/tcp`, `5060/udp` e `10000-10100/udp` liberadas;
- IP público da VPS autorizado no softswitch para as rotas TECH.

Não publique PostgreSQL, Redis, PHP-FPM, AMI ou a porta 8088 diretamente.

## 2. Criar o serviço

No Easypanel:

1. crie um projeto;
2. selecione **New Service → Compose**;
3. em Source, escolha GitHub/Git;
4. repositório: `https://github.com/Thulio94/pabxthconect`;
5. branch: selecione a branch que contém esta pasta (ou `main` depois do merge do PR);
6. Build Path: `/`;
7. Docker Compose File: `production/compose.yaml`;
8. copie `production/.env.example` para o editor Environment e substitua todos os valores de exemplo;
9. execute Deploy.

O primeiro deploy executa automaticamente as migrações, o cache do Laravel e o provisionamento do Asterisk.

## 3. Gerar segredos

Gere a `APP_KEY` sem usar sites externos:

```bash
docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Gere senhas independentes para PostgreSQL, Redis e AMI:

```bash
openssl rand -base64 36
```

Não reutilize senhas e não faça commit do arquivo `.env` real.

## 4. Configurar domínios no Compose Service

Adicione dois domínios no Easypanel:

| Domínio | Serviço interno | Porta | Protocolo interno |
|---|---:|---:|---|
| `phone.seudominio.com.br` | `nginx` | `80` | HTTP |
| `ws.seudominio.com.br` | `asterisk` | `8088` | HTTP |

Ative HTTPS/Let's Encrypt nos dois. O segundo domínio transforma a conexão externa em WSS e encaminha o upgrade WebSocket para o Asterisk.

As variáveis precisam corresponder exatamente:

```env
APP_URL=https://phone.seudominio.com.br
PBX_SIP_DOMAIN=ws.seudominio.com.br
PBX_WEBSOCKET_URL=wss://ws.seudominio.com.br/asterisk/ws
```

## 5. Volumes persistentes

O Compose cria cinco volumes:

- `postgres_data`: banco;
- `redis_data`: sessões, cache e filas;
- `app_storage`: storage do Laravel;
- `pbx_runtime`: ramais, rotas e credencial AMI gerados;
- `pbx_recordings`: gravações compartilhadas por Laravel e Asterisk.

Não renomeie serviços ou volumes depois de iniciar a produção sem antes exportar os dados.

## 6. Backups

Configure no mínimo:

- dump diário do PostgreSQL para S3/R2/B2/SFTP;
- backup diário do volume de gravações para armazenamento externo;
- retenção de 14 a 30 backups do banco;
- teste de restauração antes de colocar clientes em produção.

Snapshots da VPS não substituem backup externo.

## 7. Verificação após o deploy

Confirme nos logs que `app`, `nginx`, `postgres`, `redis`, `asterisk`, `queue`, `scheduler` e `pbx-events` estão ativos.

No shell do serviço `app`:

```bash
php artisan about
php artisan migrate:status
php artisan pbx:provision
```

No shell do Asterisk:

```bash
asterisk -rx "core show uptime"
asterisk -rx "pjsip show endpoints"
asterisk -rx "pjsip show registrations"
```

Depois valide, nesta ordem:

1. acesso HTTPS ao painel;
2. login do ramal;
3. registro WebRTC;
4. microfone e áudio;
5. chamada de saída;
6. áudio nos dois sentidos;
7. gravação e reprodução;
8. painel de acompanhamento;
9. escuta, sussurro e entrada;
10. backup manual e restauração de teste.

## 8. Observações de capacidade

O intervalo `10000-10100/udp` oferece 101 portas RTP e é apropriado para a primeira operação. Antes de ultrapassar aproximadamente 20 chamadas simultâneas, faça teste de carga e considere ampliar o intervalo RTP.

O serviço Asterisk deve manter uma única réplica. Não ative zero-downtime ou múltiplas réplicas para Asterisk, PostgreSQL ou Redis.
