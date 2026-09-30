# Auditoria de licenças e ações da interface

## Licenças de telefonia

- Uma licença corresponde a um agente autenticado na tela `/telefone`; contas administrativas não consomem licença.
- A tela renova a reserva a cada 20 segundos. O prazo de tolerância é 120 segundos sem heartbeat, suficiente para oscilações/reconexões breves do navegador.
- Fechar navegador, desligar o computador ou perder a conexão interrompe o heartbeat. A reserva expirada é removida por `pbx:licenses:reap-stale`, agendado a cada minuto; ao abrir supervisão/administração ou ao entrar novamente, a limpeza também é executada sob demanda.
- Portanto, liberação automática ocorre em até aproximadamente 3 minutos após o último heartbeat (120 s de tolerância + até 60 s até a próxima execução agendada). Supervisão considera offline após 45 s sem presença e nunca mostra chamada ativa sem licença e presença recentes.
- A limpeza encerra a sessão de operador no instante do último heartbeat, termina pausas abertas, marca presença como offline e grava `session_expired` para auditoria. Sair normalmente e deslogar administrativamente liberam a reserva imediatamente.
- Para operação da agenda, o scheduler Laravel deve estar ativo. Se ele estiver parado, acesso às telas administrativas e novo login ainda limpam reservas expiradas, mas a liberação em segundo plano depende da agenda.
- Alteração envolve migração aditiva (`last_seen_at`), heartbeat protegido pela sessão exata e lock por empresa na reserva/liberação. Não altera registro SIP de clientes externos.

## Auditoria de formulários e botões

- Formulários mutáveis do painel `/administracao` passam a ser enviados por `fetch`, preservam a posição de rolagem, atualizam o painel sem recarga e apresentam resultado/erro em feedback central padronizado. Confirmações existentes continuam usando o modal comum.
- Formulários já assíncronos de usuários/ramais e pausas continuam usando seus próprios fragmentos e feedback, sem dupla submissão.
- Chamadas, pausas, presença, acompanhamento e ações de supervisão já usam handlers `fetch`/JSON.
- Login, troca de senha e saída continuam como navegação completa por serem transições de autenticação. Filtros GET e paginação continuam navegando para refletir a consulta/URL; não são mutações.
- A proteção contra duplo clique desabilita o botão enquanto o pedido está em andamento; falhas de validação/rede não limpam os dados digitados nem recarregam a página.

## Validação antes da publicação

Executar testes de fluxo de licença, supervisão, ações administrativas e build JavaScript. Em ambiente implantado, confirmar que o scheduler está ativo e validar uma sessão abandonada em homologação. Não fazer testes de chamada real nem publicar sem autorização explícita.
