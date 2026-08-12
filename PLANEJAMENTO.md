# Arquitetura — Thconect PBX SaaS

## Objetivo

O produto passa a operar seu próprio PBX. O Laravel administra empresas, rotas, usuários, ramais, histórico e retenção; o Asterisk/PJSIP faz o registro WebRTC, a mídia, a discagem e a gravação.

```text
Superadmin
  ├─ cria rota SIP (TECH/IP ou usuário/senha)
  ├─ cria empresa e vincula uma ou mais rotas por prioridade
  └─ cria usuário → sistema reserva ramal e senha SIP

Agente no navegador
  └─ WebRTC (WSS) → Asterisk/PJSIP → rota SIP → softswitch
                                      └─ TECH + E.164, quando a rota é TECH
```

Não existe mais `internal_token` do discador como parte da nova operação. Ele continua no banco somente durante a transição do protótipo anterior e não é usado pelo novo provisionamento.

## Dados e isolamento SaaS

- `tenants`: empresa, status, faixa de ramais (padrão 999–10000) e retenção de gravação (padrão 90 dias).
- `sip_trunks`: rota compartilhável, em modo `ip_tech` ou `userpass`; credenciais são criptografadas no banco.
- `tenant_sip_trunks`: associação empresa–rota, com prioridade e ativação.
- `extensions`: ramal visível, usuário SIP técnico único, senha SIP criptografada e nó PBX.
- `call_records` e `recordings`: metadados de chamada, duração, causa de desligamento, arquivo e data de expiração.

As credenciais de rota nunca são entregues ao navegador. O navegador conhece somente a credencial SIP temporariamente necessária ao ramal autenticado.

## Discagem TECH

Para uma rota TECH, não há REGISTER no softswitch: o softswitch autoriza o IP público do PBX. O dialplan forma a chamada assim:

```text
TECH + E.164
8033 + 551736214392 = 8033551736214392
```

O frontend envia somente o número normalizado para E.164. A aplicação do prefixo TECH pertence exclusivamente ao Asterisk, para que o agente não tenha acesso nem possa alterar a rota.

## Gravações

- A gravação é feita no Asterisk com `MixMonitor`, não pelo navegador.
- O arquivo fica inicialmente no volume de gravações do PBX, com metadados no Laravel.
- A política da empresa define a expiração: padrão de 90 dias, configurável por administrador autorizado.
- A exclusão será executada por job e auditada; agentes não removem gravações.

## Ambiente local Docker

O serviço `asterisk` publica, apenas para desenvolvimento:

- SIP UDP: `5060`
- WebSocket HTTP: `8088` (`ws://localhost:8088/asterisk/ws`)
- RTP: `10000–10100/UDP`

Em VPS, WebRTC exige HTTPS/WSS com certificado válido, IP público fixo, faixa RTP liberada e firewall restrito. A porta AMI não será publicada; Laravel comunica-se apenas pela rede interna Docker.

## Estado atual

Concluído:

- Esquema de banco SaaS/PBX aditivo.
- Serviço Asterisk 20 com PJSIP, WebSocket e RTP no Docker.
- Gerador de configuração PJSIP/dialplan e recarga controlada por AMI.
- Alocação segura de ramal sequencial por empresa e geração de senha SIP forte.
- Teste automatizado da alocação e do formato TECH.

Próximas entregas:

1. Substituir o painel administrativo legado por cadastro de rota, empresa, vínculo e usuário/ramal.
2. Trocar o login Webphone para credenciais dos ramais internos.
3. Persistir eventos do Asterisk em `call_records`, disponibilizar histórico e player das gravações.
4. Implementar retenção, auditoria, monitoramento e guia de deploy VPS.
