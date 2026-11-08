# Validação dos ajustes de submenu e Painel

[Compilação Windows x64 aprovada](https://github.com/marcosliraxx/GesTI/actions/runs/42437681359).

Foram aprovadas 31 verificações em Chromium/Playwright usando as páginas locais reais de Painel, Internet, Pente-Fino e Backup. Incluem largura e margens, ausência de regressão JavaScript, cabeçalho do Painel oculto, filtros começando no topo, manutenção do cabeçalho oculto após reconstrução do DOM, dispositivos recebidos pela ponte emulada e abertura do diálogo de detalhes. A prévia composta verifica também o destaque retangular e a largura uniforme das divisórias.

O JavaScript foi validado e o ZIP de produção foi conferido por CRC. Os dois arquivos do ZIP correspondem aos arquivos sobrepostos no snapshot de compilação aprovado.

A compilação usa a base completa disponível das entregas anteriores, com o ajuste novo. O código de produção 5.0.114 não foi fornecido; essa versão é usada como identificador do build de compatibilidade, sem alterar o VERSION da instalação.

A prévia é uma composição em navegador, com dados de exemplo. Não é captura de uma sessão WinUI. O clique real dos favoritos e a troca dos menus nativos no Windows não foram automatizados; a extensão mantém o construtor de navegação existente e reaplica o padrão visual aos novos itens após sua reconstrução.

Use somente `ATUALIZACAO_SUBMENUS_PAINEL_CPANEL.zip` para atualizar os fontes no cPanel. O snapshot de validação não é pacote de produção.
