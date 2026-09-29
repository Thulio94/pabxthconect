# Licenças da tela de telefonia

Cada empresa possui um limite de agentes conectados simultaneamente à tela de telefonia. A licença é reservada no login do agente e fica registrada em `phone_license_leases`.

- Agentes ocupam uma vaga somente enquanto estão conectados à tela do telefone.
- Administrador da empresa e superadmin não consomem vagas.
- O mesmo agente não pode abrir duas sessões de telefone.
- A vaga é liberada ao usar **Sair** ou quando um superadmin usa **Deslogar** na lista de licenças da empresa.
- Uma sessão abandonada continua ocupando vaga até ser deslogada pelo superadmin. Não há liberação automática por heartbeat.
- O limite bloqueia somente a tela web; não altera registro SIP direto, rotas, TECH, Asterisk ou chamadas em curso.

Na implantação da migration, cada empresa existente recebe como limite inicial a quantidade de usuários com perfil `agent`. Sessões abertas no momento da migration são importadas como reservas para não liberar vagas indevidamente.
