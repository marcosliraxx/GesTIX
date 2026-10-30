# Arcoll 3.1.0 — uso compacto no celular

Aplicar sobre a versão 3.0.0. Plataforma, agente e cliente Windows passam juntos para 3.1.0. O ZIP contém apenas arquivos novos/modificados e os 11 marcadores de exclusão de implementações antigas. Não há nova migração SQL nesta versão.

## O que muda

- Dispositivos no celular: lista compacta de nome, endereço e situação; os demais dados continuam no modal do equipamento. Busca, filtros, ordenação e seleção em lote continuam disponíveis. Os filtros ficam em uma janela própria. Novo botão para baixar a lista filtrada em CSV, com proteção contra fórmulas de planilha.
- Mapa: enquadramento inicial real, sem o limite antigo de zoom que cortava redes maiores; organização em linhas de grupos, ajuste ao girar a tela e controles por ícones. As posições móveis usam preferência separada das posições do desktop, preservando o desenho salvo anteriormente no computador. Com muitas máquinas, a visão inicial é panorâmica; aproxime ou toque para ler os detalhes.
- Login: uma implementação de layout consolidada, sem as regras conflitantes que cortavam a tela. A opção de posição configurada pelo administrador permanece no desktop. O celular mantém campos legíveis e permite rolagem quando a altura ou o teclado exigirem.
- Planos: cartões menores, duas colunas no celular e detalhes/contratação em modal. A edição administrativa também abre em modal. Credenciais, regras de pagamento e permissões foram mantidas.
- Notificações: títulos e explicações em linguagem comum, sem mostrar os códigos internos de eventos. Os registros originais de auditoria permanecem no banco. Administradores passam a ver suas próprias atividades no sino; informações de outras contas não são incluídas.
- Páginas de usuário e administração: ações estáticas mais compactas com ícones, título e nome acessível; explicações longas ficam sob o botão de informação. Campos necessários para contratar, configurar ou confirmar ações continuam identificados.
- Windows: novas verificações por botão e resultado em janela, sem precisar escrever comandos. Mensagens de erro do cliente de suporte passam a explicar problemas de conexão e certificado em linguagem comum. O terminal avançado existente foi preservado.

## Novas ferramentas

Acesse **Rede → Ferramentas**, escolha um computador e toque na verificação. O gestor continua sendo o responsável por autorizar essas tarefas.

- **Discos:** confere espaço disponível em volumes locais e avisa abaixo de 15%. Não testa SMART, não diagnostica defeito físico e não apaga arquivos.
- **Serviços:** confere serviços essenciais do Windows e diferencia serviços automáticos parados de serviços configurados para não iniciar automaticamente. Não reinicia nem altera serviços.
- **Reiniciar?:** confere se o Windows registrou uma reinicialização pendente. Não reinicia o computador.
- Internet, abertura de sites e recursos do computador reutilizam as verificações existentes, agora reunidas com as novas em uma área de poucos cliques.

Atualize o agente antes de usar as novas verificações. Um agente antigo pode não conhecer essas tarefas. Os resultados dependem do computador estar conectado e das permissões de leitura oferecidas pelo Windows.

## Instalação e retorno

1. Faça backup dos arquivos e do banco antes de aplicar.
2. Extraia o ZIP localmente. Envie apenas os arquivos não vazios para os mesmos caminhos, preservando `includes/config.php`, uploads, histórico e configurações privadas.
3. Não envie os arquivos vazios para sobrescrever arquivos. Eles indicam os caminhos antigos que devem ser excluídos manualmente, caso ainda existam. A lista está no final do LEIA-ME.
4. Envie a pasta de ferramentas e os módulos atualizados; recarregue o PWA. Atualize o agente instalado pelo instalador da plataforma. Para o cliente de suporte, baixe novamente o instalador Windows. Preserve a configuração e a identidade das instalações existentes.
5. Para desfazer, restaure os arquivos e instaladores da 3.0.0. Não é necessário reverter o banco. Evite misturar arquivos de versões diferentes.

Os novos campos não exigem alteração do banco. A migração 3.0.0 anteriormente instalada deve continuar no servidor, pois pertence à instalação base.

## Verificação realizada

- 224 combinações de páginas administrativas/usuário, temas e tamanhos de tela.
- 39 cenários específicos com inventário populado, até 48 dispositivos e dez grupos, modais, ferramentas e login. A interação de resultados das ferramentas foi exercitada com resposta simulada; não representa execução em Windows físico.
- 35 cenários do painel: paginação, busca, filtros, modais e navegação.
- 25 testes Python, incluindo construção das janelas Tkinter e verificações dos novos diagnósticos com entradas controladas.
- Sintaxe PHP, JavaScript, Python e CSS verificada. Não foram geradas capturas de tela.

Windows físico, dispositivos Android/iPhone reais, gateways e SMTP externos não foram testados nesta correção. Os testes de navegador e Tkinter não substituem esses testes em equipamento real.

`VERSION` é o caminho relativo completo do arquivo de versão em texto simples, que não admite comentário. Marcadores de exclusão ficam obrigatoriamente sem conteúdo.
