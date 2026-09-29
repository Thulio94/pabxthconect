# Auditoria Laravel, segurança e desempenho

Data: 2026-09-29
Escopo: revisão do PABX Thconect; alterações locais preparadas para publicação.
Referências de trabalho: skill `laravel-specialist` instalada em `C:\Users\Thulio\.agents\skills\laravel-specialist` e guardrails do PABX em `docs/PBX_GUARDRAILS.md`.

## Mudanças aplicadas

- Atualizado `composer.lock` para Laravel Framework 13.34.0, CommonMark 2.10.3 e Flysystem 3.36.0 (Flysystem Local 3.35.3), junto das dependências compatíveis. O `composer audit --locked` deixou de apontar advisories.
- Gravações novas agora iniciam pelo único handler pós-atendimento `U(...)` do `Dial`, executado na perna chamada após resposta e antes da bridge. Chamadas que não chegam a `DialStatus=ANSWER` não iniciam MixMonitor nem criam WAV. Empresas sem gravação mantêm a opção de continuação `g`; `g` não é combinado com `U`. Os dados de histórico continuam sendo mantidos.
- `DialEnd` com `ANSWER` marca atendimento mesmo se `BridgeEnter` não chegar; `BridgeEnter` continua como atualização idempotente. O `Hangup` de uma perna de trunk já não finaliza o registro principal, permitindo a próxima rota de failover.
- Provisionamento carrega `extensions.user` antecipadamente, evitando uma consulta por ramal para resolver permissões no dialplan.
- Expurgo por retenção processa no máximo 100 gravações por lote, evitando carregar todo o acervo na memória.
- Atualizados os guardrails e o runbook para refletir o contrato de gravação após atendimento.

Nenhum arquivo WAV histórico, registro de gravação existente ou volume foi removido ou alterado.

## Controles verificados

- `APP_DEBUG=false`, `SESSION_DRIVER=redis` e `SESSION_ENCRYPT=true` estão definidos no Compose de produção.
- Autenticação do agente tem limitação de tentativas; rotas do telefone exigem middleware de sessão/ramal/licença.
- Reprodução de gravações valida atendimento, existência/validade do arquivo e escopo do agente/empresa.
- As rotas administrativas estão atrás de autenticação, troca obrigatória de senha e middleware de perfil.
- O contrato de discagem E.164/TECH e a geração PJSIP não foram alterados.

## Riscos e melhorias pendentes

1. **Prioridade alta — permissão do volume de áudio.** `PbxConfigGenerator` força `0777` no diretório compartilhado `pbx_recordings`. Isso permite leitura, escrita e remoção por processos não proprietários dentro do volume. Não foi reduzido nesta alteração: Laravel e Asterisk usam UIDs diferentes e reduzir diretamente para `0750` poderia impedir que o Laravel leia as gravações. Próximo passo seguro: criar GID compartilhado explícito nos dois serviços Compose, testar leitura/escrita em homologação, aplicar diretórios `2770` e WAV `0660`, planejar ajuste de permissões do volume existente e somente então publicar.
2. **Prioridade média — listagem de gravações.** `AdminRecordingController` pode executar uma consulta de recuperação de duração para cada linha da página (até 25 consultas adicionais). É bounded por paginação, mas pode ser substituído por uma consulta agregada/batch depois de medir com volume representativo e cobrir a equivalência dos formatos telefônicos.
3. **Qualidade/estrutura.** O `Pint --test` global apontou 29 problemas de estilo preexistentes em arquivos não relacionados e áreas já modificadas no workspace. Não apliquei formatação em massa para preservar alterações de trabalho já existentes. Os seis arquivos PHP desta revisão passaram no Pint; é recomendável uma tarefa dedicada, por diretório/PR, para o restante.
4. **Cobertura.** A suíte funcional completa passou, mas a métrica percentual de cobertura (>85% recomendada pela skill) não foi medida; o runtime atual não foi configurado para coleta de cobertura. Adicionar PCOV/Xdebug no CI é uma melhoria separada.
5. **Mídia real.** A mudança foi testada pelo dialplan gerado e eventos AMI simulados; não houve chamada real nem validação em Asterisk/Easypanel. A sintaxe e o comportamento final precisam de uma chamada de homologação antes de produção, inclusive teste de failover e confirmação de WAV reproduzível.

## Verificações executadas

- Testes: `64 passed`, `359 assertions`, usando SQLite em memória e cache array; nenhum banco PostgreSQL foi usado.
- Auditoria Composer: `No security vulnerability advisories found` após atualização do lock.
- Build front-end: `npm run build` passou (Vite 8.3.1).
- Pint: os seis arquivos PHP alterados nesta revisão passaram na verificação isolada; a verificação global identificou os 29 desvios preexistentes.
- Guardrails PBX: `scripts/verify-pbx-invariants.ps1` passou com todas as verificações críticas.

## Checklist de publicação

1. Revisar diff e garantir que alterações pré-existentes do workspace continuam intactas.
2. Em homologação, gerar e recarregar o dialplan; confirmar `Dial(...,40,U(record-call-...))` e que `MixMonitor` só existe no contexto chamado após resposta. Para Asterisk, `U()` não deve ser combinado com outras ações pós-atendimento.
3. Testar chamadas atendidas, não atendidas, ocupadas, canceladas e failover; confirmar ausência de WAV para as quatro últimas e WAV válido para a atendida.
4. Confirmar `DialStatus=ANSWER`, histórico, duração, AMI `MixMonitorStop`, leitura Laravel e reprodução.
5. Publicar somente com autorização explícita; monitorar serviços e manter rollback para a versão anterior.
