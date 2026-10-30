# Preferências deste projeto

- Toda entrega com atualização ou correção deve aumentar a versão da plataforma e de todos os componentes versionados do agente. Use `python3 scripts/bump_version.py X.Y.Z` e execute `tests/test_release_version.py`. A versão única está em `VERSION`; metadados de backups não são versões da aplicação.
- Entregue um ZIP com os arquivos atualizados nas pastas corretas e instruções em português para instalar, testar e desfazer. Não inclua config.php, credenciais, uploads, banco de produção ou o backup original.
- Mantenha os temas claro, escuro e automático, a navegação mobile e os testes responsivos. Não reduza fontes a ponto de prejudicar leitura ou causar zoom automático de campos no iPhone. Dados extensos e formulários podem rolar dentro de painéis; controles principais devem permanecer acessíveis.
- Não gere imagens de apresentação nem capturas de tela sem pedido do usuário. Valide a interface por testes e medições no navegador.
- Use o checkout existente; tarefas em nuvem já são isoladas. Não crie worktrees sem solicitação.
- Mudanças no GitHub e pacotes ZIP não atualizam o cPanel nem os agentes instalados. Informe claramente os passos que o usuário precisa executar.

- Alterações visuais devem ser feitas nas declarações e estruturas originais. Não criar arquivos de sobreposição, reinterpretar estilos em execução ou interceptar métodos de Canvas/Tkinter. Reutilizar os arquivos existentes e remover implementações substituídas.

- Arquivos de código criados ou modificados devem identificar seu caminho relativo completo em comentário no topo. Os formatos sem comentários devem ter o caminho indicado na entrega.
- Gere um único ZIP com as mudanças desde a base fornecida, migrações e marcadores de zero bytes nos caminhos obsoletos; esses marcadores indicam exclusão manual e não devem ser enviados para sobrescrever arquivos.
- Preserve a cobrança desativada até configuração explícita do administrador. Não confirme pagamentos por retorno de checkout ou referência Pix enviada pelo cliente. Não inclua chaves de gateways, códigos de sessão ou arquivos de fixtures nos pacotes.

- Valide Dispositivos e Mapa no celular com inventário populado, nomes longos e vários grupos, incluindo o enquadramento inicial da topologia e os modais; ausência de overflow no body não basta.
- No celular, priorize cartões compactos e detalhes em diálogos explícitos; ações por ícone precisam de aria-label/título. Notificações públicas devem usar textos de arc_notice_copy, mantendo códigos e detalhes originais somente na auditoria.

- Controles de energia devem usar argumentos Windows fixos, sem shell, sem forçar arquivos não salvos, com confirmação do gestor e revalidação da tarefa antes da execução. Operadores/convidados não podem desligar computadores. Testes devem simular as chamadas de energia.
- Pix copia e cola/QR deve ser gerado localmente e preservar os dados da cobrança emitida. Informar pagamento nunca libera Pix manual; a conferência administrativa permanece obrigatória. Valide QR com leitura independente, isolamento do checkout e migração reaplicável sem perda de cobranças.

- O mapa abre ajustado pela largura; visão geral é ação explícita. Valide ambos os modos. A rolagem por toque sobre dispositivos deve funcionar; movimentação por toque fica em modo de edição explícito.
- Transferências usam o armazenamento privado, fila/autenticação existentes e autorização local para selecionar arquivo ou destino. Não executar arquivos nem aceitar caminhos remotos arbitrários. Validar integridade, tamanho, permissões revalidadas, isolamento por solicitante, expiração e limpeza preservando metadados. Testes não devem coletar arquivos reais de usuários.

- Nunca gerar ZIP de atualização com a data padrão de 1980. Arquivos substituídos precisam ter data real de empacotamento para que PHP OPcache e caches de recursos detectem a alteração. Validar metadados do ZIP e testar extração/cache quando mudar o empacotamento.
- Ao organizar um backup, comparar somente caminhos públicos aprovados; não incorporar configuração, uploads, logs, SQL de produção ou metadados antigos de backup. Em recuperação de cache, renovar também a data dos arquivos de código já atuais e informar isso na entrega.
