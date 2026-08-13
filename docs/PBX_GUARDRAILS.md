# Proteções críticas do PABX Thconect

## Objetivo

Este documento define contratos que não podem ser alterados silenciosamente. Mudanças aparentemente pequenas na formatação do telefone, no dialplan, nos eventos AMI ou nos volumes podem interromper chamadas, histórico e gravações de todas as empresas.

## Fluxo canônico de uma chamada de saída

```text
Operador digita DDD + número
        ↓
Navegador limpa caracteres e envia 55 + DDD + número
        ↓
Asterisk identifica empresa pelo contexto do ramal
        ↓
Busca rotas ativas vinculadas à empresa por prioridade
        ↓
Monta TECH da rota + 55 + DDD + número
        ↓
Envia pelo endpoint PJSIP trunk-ID
```

Exemplo: telefone `17 3621-4392`, TECH `8033` → destino SIP `8033551736214392`.

### Divisão de responsabilidade

| Camada | Responsabilidade | Não deve conhecer |
|---|---|---|
| Navegador/JsSIP | Validar telefone e enviar E.164 brasileiro | TECH, host da operadora, AMI e empresa |
| Laravel | Autenticar, identificar empresa/ramal, persistir histórico e gerar configuração | Senha SIP em resposta pública |
| Asterisk | Acrescentar TECH, selecionar trunk, gravar e gerar eventos | — |
| Softswitch | Aceitar o IP autorizado e rotear o destino TECH+E.164 | Usuário da tela |

## Incidente que originou esta proteção

Sintoma: chamadas retornavam `SIP 404 — Not Found` após uma alteração de formato. O destino chegava à telefonia como `55 + DDD + número`, mas a operadora exige `TECH + 55 + DDD + número`.

Correção permanente:

- `resources/js/app.js` envia somente um E.164 brasileiro;
- `PbxConfigGenerator` normaliza o destino no servidor;
- cada `Dial()` acrescenta `sip_trunks.tech_prefix`;
- múltiplas rotas são geradas por prioridade, cada uma com sua TECH;
- `PbxProvisioningTest` impede regressão desse contrato.

## Arquivos críticos

| Área | Arquivos principais | Risco |
|---|---|---|
| Discagem | `app/resources/js/app.js` | Duplicar/remover 55, expor TECH |
| Dialplan | `app/app/Services/Pbx/PbxConfigGenerator.php` | Parar chamadas de todas as empresas |
| Rotas | `SipTrunk`, `Tenant`, `tenant_sip_trunks` | Usar TECH/trunk de outra empresa |
| Eventos | `AmiEventProcessor.php`, serviço `pbx-events` | Perder duração, status, vínculo e gravação |
| Gravação | `MixMonitor`, `RecordingReconciler`, `pbx_recordings` | Arquivo inexistente ou painel sem áudio |
| Produção | `production/compose.yaml` | Perder dados, rede, RTP ou persistência |

## Contrato de gravação

1. `MixMonitor` inicia no contexto da empresa antes do `Dial`.
2. O caminho deve ser `tenant-{id}/${UNIQUEID}.wav` dentro de `${RECORDING_ROOT}`.
3. O volume `pbx_recordings` deve ser montado no Laravel e em `/var/spool/asterisk/monitor` no Asterisk.
4. `pbx-events` associa `UNIQUEID` e `Linkedid` ao registro da chamada.
5. O navegador pode produzir WebM de contingência, mas o WAV do Asterisk é a fonte principal quando disponível.
6. Não considerar a gravação válida apenas porque existe uma linha no banco; confirmar arquivo, tamanho, duração e reprodução.

## Contrato de provisionamento

- Toda criação/edição/exclusão de empresa, rota, vínculo ou ramal deve executar o provisionamento.
- A geração escreve atomicamente `pjsip_endpoints.conf`, `pjsip_trunks.conf` e `extensions_tenants.conf` no volume `pbx_runtime`.
- Após gerar, executar reload pelo AMI. Se o AMI falhar, registrar claramente que a configuração ainda não entrou em vigor.
- Asterisk deve manter uma única réplica.

## Contrato de supervisão

- `SPYGROUP` identifica somente o canal WebRTC do agente. Não usar `__SPYGROUP`, pois a herança leva o grupo para a perna da operadora e o `ChanSpy` pode selecionar o canal errado.
- Escuta usa `ChanSpy(...,qbg(...))`, sussurro usa `qbwg(...)` e entrada usa `qbBg(...)`.
- O navegador deve recuperar faixas remotas tanto no evento `track` quanto em `RTCPeerConnection.getReceivers()`, porque a faixa pode existir antes da instalação do listener.
- Em "Só ouvir", o microfone do supervisor permanece desabilitado no `RTCRtpSender`; o Asterisk também não habilita `w` ou `B` nesse modo.

## Mudanças proibidas sem plano de migração

- Colocar TECH no JavaScript ou devolvê-la em API pública.
- Remover o prefixo `55` do fluxo de saída.
- Usar uma TECH fixa no código.
- Selecionar rota sem filtrar empresa, vínculo ativo e prioridade.
- Remover `pbx-events`, `queue`, `scheduler`, `MixMonitor` ou os volumes persistentes.
- Alterar nomes/caminhos dos volumes em produção sem backup e restauração testada.
- Fazer `docker compose down -v`, apagar volumes ou recriar banco em produção.
- Expor UDP 5060 indiscriminadamente; a rota TECH deve usar IPs autorizados e firewall.
- Aplicar `DROP --dport 5060` sem limitar a interface de entrada: isso também bloqueia os INVITEs que saem do contêiner para o softswitch. Como este PBX somente origina chamadas, não publicar 5060 no Compose; se houver regra adicional, bloquear apenas tráfego novo recebido pela interface pública.

## Checklist para mudanças críticas

O workflow `.github/workflows/pbx-guardrails.yml` repete testes e build no GitHub. Configure a proteção da branch `main` para exigir o sucesso de **PBX critical guardrails / validate** antes de aceitar mudanças.

### Antes de editar

- Identificar todas as camadas afetadas: navegador, Laravel, Asterisk, softswitch e Easypanel.
- Ler os testes existentes antes de alterar o comportamento.
- Registrar o formato de entrada e o destino SIP esperado.
- Preservar compatibilidade multiempresa e isolamento de dados.

### Antes do commit

```powershell
powershell -ExecutionPolicy Bypass -File scripts/verify-pbx-invariants.ps1 -RunTests
docker compose exec -T app php artisan pbx:provision
docker compose exec -T asterisk asterisk -rx "dialplan show tenant-ID"
```

Confirmar no dialplan:

- `TH_DEST` contém exatamente `55 + DDD + número`;
- cada `Dial` contém a TECH correta antes de `${TH_DEST}`;
- rotas aparecem na prioridade cadastrada;
- `MixMonitor` aparece antes de `Dial` quando a empresa grava chamadas.

### Depois do deploy

1. Confirmar `app`, `nginx`, `postgres`, `redis`, `asterisk`, `queue`, `scheduler` e `pbx-events` ativos.
2. Confirmar `/up` e os assets com HTTP 200.
3. Confirmar registro WebRTC.
4. Fazer somente uma chamada de teste autorizada.
5. Verificar no Asterisk o destino final TECH+E.164 e `DIALSTATUS`.
6. Confirmar histórico, duração, WAV, reprodução e painel de acompanhamento.
7. Se houver regressão, interromper novas mudanças e reverter o commit; não improvisar diretamente dentro do contêiner.

## Diagnóstico rápido

| Sintoma | Verificar primeiro |
|---|---|
| SIP 404 | TECH cadastrada, vínculo/prioridade e `Dial(PJSIP/TECH${TH_DEST}@trunk-ID)` |
| SIP 403 | IP público autorizado, permissão da rota e origem |
| SIP 603 | Bloqueio, saldo, regra do softswitch ou destino recusado |
| Registra mas não liga | contexto do ramal, rota vinculada e dialplan carregado |
| Chamada sem histórico | `pbx-events`, AMI, `Uniqueid`/`Linkedid` |
| Sem gravação | `MixMonitor`, volume compartilhado, permissões e reconciliador |
| Duração zero | eventos Answer/Hangup e união entre registro WebRTC e AMI |

## Recuperação segura

Não editar arquivos gerados dentro do contêiner: serão sobrescritos. Corrigir banco ou gerador, executar testes, provisionar e recarregar. Antes de qualquer operação em volumes, produzir backup verificável do PostgreSQL e de `pbx_recordings`.

Para diagnóstico e operação em produção, seguir o [runbook de telefonia e gravações](PRODUCTION_RUNBOOK.md).
