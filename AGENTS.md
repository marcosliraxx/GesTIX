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
