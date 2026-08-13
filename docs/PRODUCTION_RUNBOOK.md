# Runbook de telefonia e gravações em produção

Este documento registra o procedimento validado no incidente de 13/08/2026. Use-o antes de alterar discagem, rotas TECH, PJSIP, firewall, RTP, MixMonitor, volumes ou o deploy do Asterisk.

## Contrato que não pode mudar

```text
Operador informa DDD + número (10 ou 11 dígitos)
Navegador envia 55 + DDD + número
Asterisk identifica tenant e rota ativa
Asterisk envia TECH da rota + 55 + DDD + número
Softswitch roteia a chamada pelo IP autorizado
```

Exemplo: número `17 3621-4392` e TECH `8033` resultam em `8033551736214392`.

- O navegador nunca recebe nem envia a TECH.
- A TECH vem de `sip_trunks.tech_prefix`.
- O vínculo e a prioridade vêm de `tenant_sip_trunks`.
- O Asterisk deve mostrar `Dial(PJSIP/TECH${TH_DEST}@trunk-ID,60,g)`.
- `t1-e999` e `t3-e999` são ramais técnicos de tenants diferentes. Sempre diagnosticar o tenant observado no canal e não assumir a empresa pelo número visível do ramal.

## Serviços e nomes operacionais

O Compose de produção deve manter exatamente `app`, `nginx`, `postgres`, `redis`, `asterisk`, `queue`, `scheduler` e `pbx-events`.

Contêineres usados nos comandos:

```bash
PBX_CONTAINER="pabx-thconect_pabx-thconect1-asterisk-1"
APP_CONTAINER="pabx-thconect_pabx-thconect1-app-1"
```

Variáveis definidas no shell desaparecem quando uma nova sessão SSH é aberta. Se `$PBX_CONTAINER` estiver vazio, defina-o novamente ou use o nome completo.

## Diagnóstico de uma chamada

Executar uma linha por vez. Não copiar o prompt `root@servidor:~#`, o banner da VPS ou saídas anteriores como comandos.

Ativar o logger:

```bash
sudo docker exec "$PBX_CONTAINER" asterisk -rx "pjsip set logger on"
```

Fazer uma única chamada de teste autorizada e consultar:

```bash
sudo docker logs --since 3m "$PBX_CONTAINER" 2>&1 | grep -E "Executing|Dial\(PJSIP|INVITE sip:|SIP/2.0 [1-6][0-9][0-9]|DIALSTATUS|CHANUNAVAIL|Everyone is busy"
```

Desativar o logger:

```bash
sudo docker exec "$PBX_CONTAINER" asterisk -rx "pjsip set logger off"
```

Nunca compartilhar linhas `Authorization`, credenciais, tokens ou ambientes do Easypanel.

### Como interpretar

| Evidência | Significado |
|---|---|
| `401 Unauthorized` seguido de nova tentativa e `200 OK` no registro WebRTC | Desafio SIP normal, não falha de senha por si só |
| `Dial(PJSIP/TECH55...@trunk-ID)` | Dialplan acrescentou a TECH corretamente |
| `INVITE sip:TECH55...@softswitch` | Asterisk realmente enviou ao softswitch |
| `100 Trying` | Softswitch recebeu a tentativa |
| `183 Session Progress` | Destino está progredindo; pode haver toque ou mídia antecipada |
| `200 OK` | Transação/chamada aceita; correlacionar pelo diálogo porque registros e chamadas aparecem misturados |
| `404 Not Found` após o INVITE externo | Conferir TECH, formato final, vínculo, prioridade e host da rota |
| `487 Request Terminated` | Uma transação foi cancelada; normalmente operador/navegador desligou ou houve cancelamento durante o toque |
| `Removed contact ... due to shutdown` | Contato WebRTC removido durante reload/reinício; não é tentativa de chamada |

Confirmar o dialplan e os hosts provisionados:

```bash
sudo docker exec "$PBX_CONTAINER" asterisk -rx "dialplan show tenant-ID"
```

```bash
sudo docker exec "$PBX_CONTAINER" sh -c "grep -E '^\[trunk-[0-9]+(-aor)?\]$|^contact=sip:' /etc/asterisk/generated/pjsip_trunks.conf"
```

Não editar esses arquivos gerados. A fonte de verdade é PostgreSQL; corrigir pelo painel/gerador e executar `php artisan pbx:provision`.

## Firewall SIP

Este PBX somente origina chamadas:

- não publicar `5060:5060/udp` no Compose;
- permitir saída UDP 5060 do Asterisk até o softswitch;
- não usar `DROP udp --dport 5060` genérico em `DOCKER-USER`, pois ele também bloqueia INVITEs novos que saem do contêiner;
- limitar bloqueios de scanners ao IP de origem invasor ou à interface pública de entrada;
- permitir `10000-10100/udp` para RTP.

Verificações:

```bash
sudo docker port "$PBX_CONTAINER" | grep 5060 || echo "OK: UDP 5060 não está publicada"
```

```bash
sudo iptables -S DOCKER-USER | grep 5060 || echo "Nenhuma regra específica para 5060"
```

Regras `DROP` com `-s IP-DO-INVASOR/32` não bloqueiam a saída do PBX. Uma regra sem restrição de origem/interface pode bloqueá-la. Se `netfilter-persistent` não existir, a regra atual funciona, mas pode não sobreviver à reinicialização; instalar e persistir firewall deve ser uma tarefa separada e revisada.

## RTP e áudio

O Asterisk usa `10000-10100/udp`, `external_media_address=PBX_PUBLIC_IP` e `local_net=PBX_LOCAL_NET`.

Se houver sinalização (`100/183/200`) mas nenhum áudio:

1. manter uma chamada atendida por pelo menos 10 segundos;
2. falar nos dois sentidos;
3. confirmar que `10000-10100/udp` estão publicados e permitidos no firewall da VPS/provedor;
4. validar o SDP e o tráfego RTP antes de alterar codecs ou dialplan.

Uma tentativa cancelada durante o toque pode gerar WAV sem áudio e não prova falha de RTP.

## Gravações

Fluxo obrigatório:

```text
MixMonitor no Asterisk
  → /var/spool/asterisk/monitor/tenant-ID/UNIQUEID.wav
volume pbx_recordings
  → /var/www/html/storage/app/pbx-recordings/tenant-ID/UNIQUEID.wav no Laravel
pbx-events/RecordingReconciler
  → metadados e reprodução no painel
```

O arquivo AMI deve permanecer `0600`. Os WAVs precisam ser `0644`, pois são criados pelo Asterisk como `root` e lidos pelo Laravel em outro contêiner. O entrypoint redefine o `umask` para `022` somente depois de proteger a credencial AMI.

### Diagnóstico do arquivo

- `44 bytes`: somente cabeçalho WAV; nenhum quadro de áudio foi gravado e não há recuperação retroativa.
- tamanho maior que `44 bytes`: há conteúdo; validar duração e reprodução.
- `root:root` não é problema se o modo for `0644`.
- `php artisan pbx:recordings:sync` retornar `0` não significa erro; não havia arquivo órfão válido a recuperar ou ele já estava associado.

Listar os mais recentes:

```bash
sudo docker exec "$PBX_CONTAINER" sh -c "ls -lhtr /var/spool/asterisk/monitor/tenant-ID | tail -n 10"
```

Confirmar o mesmo volume no Laravel:

```bash
sudo docker exec "$APP_CONTAINER" ls -lh /var/www/html/storage/app/pbx-recordings/tenant-ID/ARQUIVO.wav
```

Corrigir permissões de WAVs antigos, sem alterar diretórios ou segredos:

```bash
sudo docker exec "$PBX_CONTAINER" find /var/spool/asterisk/monitor -type f -name '*.wav' -exec chmod 0644 '{}' +
```

Sincronizar arquivos válidos:

```bash
sudo docker exec "$APP_CONTAINER" php artisan pbx:recordings:sync
```

## Validação após alteração ou deploy

1. Executar `scripts/verify-pbx-invariants.ps1 -RunTests`.
2. Confirmar os 8 contêineres ativos.
3. Confirmar `/up` e `/entrar` com HTTP 200.
4. Confirmar registro WebRTC.
5. Confirmar no log `Dial` com TECH + E.164.
6. Confirmar `INVITE` ao host correto e resposta do softswitch.
7. Manter chamada atendida e áudio bidirecional por pelo menos 10 segundos.
8. Confirmar WAV maior que 44 bytes e modo `0644`.
9. Confirmar histórico, duração, reprodução e acompanhamento.
10. Desativar `pjsip set logger on` após o diagnóstico.

## Ajustes consolidados do incidente

- TECH voltou a ser acrescentada exclusivamente no Asterisk.
- Discagem validada como `TECH + 55 + DDD + número`.
- UDP 5060 deixou de ser publicado no Docker.
- Regras genéricas que bloqueavam saída SIP foram removidas; bloqueios por IP invasor permanecem possíveis.
- O trunk foi comprovado por `INVITE`, `100 Trying`, `183 Session Progress` e `200 OK`.
- `MixMonitor` e o volume persistente foram preservados.
- Credencial AMI permanece privada em `0600`.
- Novos WAVs são legíveis pelo Laravel em `0644`.
- A verificação de invariantes impede regressão das permissões de gravação.

Nunca executar `docker compose down -v`, excluir volumes, editar arquivos gerados dentro do contêiner ou originar chamadas reais sem autorização explícita.
