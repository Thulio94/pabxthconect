# Regras obrigatórias do PABX Thconect

Estas instruções se aplicam a todo o repositório. Antes de alterar telefonia, leia também [`docs/PBX_GUARDRAILS.md`](docs/PBX_GUARDRAILS.md).

## Invariantes que não podem ser quebrados

1. O navegador envia apenas `55 + DDD + número`. Nunca enviar a TECH, credenciais SIP, AMI ou dados da empresa ao navegador.
2. O Asterisk monta o destino final no servidor: `TECH da rota + 55 + DDD + número`.
3. A TECH vem de `sip_trunks.tech_prefix`; a rota vem do vínculo ativo `tenant_sip_trunks`, em ordem de prioridade.
4. Empresas com várias rotas devem manter o failover, usando a TECH individual de cada rota.
5. Não alterar `PbxConfigGenerator`, `app.js`, PJSIP ou dialplan sem atualizar e executar `PbxProvisioningTest` e o conjunto completo de testes.
6. Não remover `MixMonitor`, `pbx-events`, o reconciliador de gravações nem os volumes compartilhados `pbx_runtime` e `pbx_recordings`.
7. Laravel grava metadados; Asterisk grava o WAV. Ambos devem apontar para o mesmo volume persistente.
8. Não renomear serviços ou volumes de produção, nem publicar PostgreSQL, Redis, PHP-FPM ou AMI.
9. Alterações de senha/ramal precisam atualizar banco, arquivos PJSIP e recarregar o Asterisk como uma única operação.
10. Nunca testar uma chamada real ou executar mudança destrutiva em produção sem autorização explícita.
11. O PBX somente origina chamadas: UDP 5060 não deve ser publicado. Regras de firewall para 5060 devem filtrar apenas entrada pela interface pública e nunca bloquear a saída do Asterisk para o softswitch.

## Validação obrigatória antes de publicar

Execute, nesta ordem:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/verify-pbx-invariants.ps1 -RunTests
docker compose exec -T app php artisan pbx:provision
docker compose exec -T asterisk asterisk -rx "dialplan show tenant-ID"
```

No dialplan, confirme visualmente `Dial(PJSIP/TECH${TH_DEST}@trunk-ID,60,g)`. Depois do deploy, confirme os oito serviços, `/up`, registro WebRTC, uma chamada autorizada e a gravação correspondente.

Se qualquer etapa falhar, não publicar.
