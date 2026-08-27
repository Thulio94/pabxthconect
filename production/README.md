# Produção no Easypanel

Esta pasta é isolada do ambiente local. O Easypanel deve usar `production/compose.yaml`; o `compose.yaml` da raiz continua destinado ao desenvolvimento.

## 1. Pré-requisitos

- Ubuntu novo com Easypanel instalado;
- IPv4 público fixo;
- DNS de `phone.seudominio.com.br` e `ws.seudominio.com.br` apontando para a VPS;
- portas `80/tcp`, `443/tcp` e `10000-10299/udp` liberadas;
- para TURN: `3478/tcp+udp`, `5349/tcp` e `49160-49359/udp` liberadas;
- saída UDP 5060 da VPS para o softswitch permitida, sem publicar 5060 no Docker;
- IP público da VPS autorizado no softswitch para as rotas TECH.

Não publique PostgreSQL, Redis, PHP-FPM, AMI ou a porta 8088 diretamente.
Não publique UDP 5060: este PBX apenas origina chamadas. Consulte o [runbook operacional](../docs/PRODUCTION_RUNBOOK.md) antes de alterar firewall, SIP, RTP ou gravações.

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

Os serviços públicos `nginx` e `asterisk` também participam da rede externa `easypanel`, permitindo que o Traefik alcance as portas internas. PostgreSQL, Redis, filas e AMI permanecem restritos à rede privada do Compose.

As variáveis precisam corresponder exatamente:

```env
APP_URL=https://phone.seudominio.com.br
PBX_SIP_DOMAIN=ws.seudominio.com.br
PBX_WEBSOCKET_URL=wss://ws.seudominio.com.br/asterisk/ws
```

## 5. Volumes persistentes

O Compose cria seis volumes:

- `postgres_data`: banco;
- `redis_data`: sessões, cache e filas;
- `app_storage`: storage do Laravel;
- `pbx_runtime`: ramais, rotas e credencial AMI gerados;
- `pbx_recordings`: gravações compartilhadas por Laravel e Asterisk.
- `turn_certs`: dados ACME, certificado e chave privados usados pelo Certbot e
  montados como somente leitura no Coturn.

O segredo AMI permanece com permissão `0600`. Os WAVs do `MixMonitor` devem ser criados com permissão `0644`, pois o Asterisk grava como `root` e o Laravel precisa ler o mesmo volume.

Não renomeie serviços ou volumes depois de iniciar a produção sem antes exportar os dados.

## 6. Backups

Configure no mínimo:

- dump diário do PostgreSQL para S3/R2/B2/SFTP;
- backup diário do volume de gravações para armazenamento externo;
- retenção de 14 a 30 backups do banco;
- teste de restauração antes de colocar clientes em produção.

Snapshots da VPS não substituem backup externo.

## 7. Verificação após o deploy

Confirme nos logs que `app`, `nginx`, `postgres`, `redis`, `asterisk`, `queue`, `scheduler`, `pbx-events`, `turn` e `turn-certbot` estão ativos. Para o TURN TLS, configure no Easypanel `CLOUDFLARE_DNS_API_TOKEN`, `TURN_CERT_DOMAIN` e `TURN_CERT_EMAIL`; o Certbot usa DNS-01, renova a cada 12 horas e pede ao Coturn recarregar o certificado sem socket Docker.

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

O intervalo `10000-10299/udp` oferece 300 portas RTP. Ele foi dimensionado com margem para o perfil inicial de até 50 chamadas simultâneas, supervisão e tentativas em paralelo. O TURN usa a faixa separada `49160-49359/udp` e deve ser validado em uma rede restritiva antes da operação.

O serviço Asterisk deve manter uma única réplica. Não ative zero-downtime ou múltiplas réplicas para Asterisk, PostgreSQL ou Redis.
