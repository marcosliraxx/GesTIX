# Submenus uniformes e Painel sem cabeçalho

Este pacote complementa o ajuste de layout compacto já aplicado no agente.

## Instalar

1. Guarde uma cópia dos dois arquivos indicados abaixo.
2. Extraia **ATUALIZACAO_SUBMENUS_PAINEL_CPANEL.zip** na raiz do sistema, mesclando a pasta `agentes/`.
3. Atualize/recompile o agente Windows. Se usa release pré-compilada, gere e publique novamente essa release. O upload de fontes no cPanel não altera o executável já instalado.

A versão atual 5.0.114 mostrada no print é preservada: o pacote não contém `VERSION`, projeto `.csproj`, APIs ou configuração de produção.

## Alterações

- Uma linha vertical uniforme de 1 DIP entre cada item do submenu, com o mesmo recuo de 7 DIP nas pontas. A linha acompanha a cor de borda do tema. Não há linha após o último item.
- Os separadores antigos por grupo ficam ocultos, evitando linhas duplicadas e espaçamentos diferentes. A ordem dos menus e os favoritos permanecem no código principal existente.
- O destaque dos submenus passa a usar cantos retos, inclusive no item ativo. As categorias da faixa superior mantêm o desenho anterior.
- No Painel, a faixa “PRESENÇA NA REDE / Painel de dispositivos / nome da rede” fica oculta. A área começa na barra de filtros, busca e visualização.
- O cabeçalho continua presente no DOM, invisível, para as consultas existentes ao nome da rede e ao estado dos agentes funcionarem. Se a página reconstruir esse cabeçalho, a extensão volta a ocultá-lo.
- A lista de dispositivos, filtros, busca, avisos de erro, histórico lateral e detalhes dos dispositivos permanecem. Os cabeçalhos de Internet, Pente-Fino e Backup não são removidos por esta alteração.

## Arquivos

- `agentes/servidor/Arcoll.WinUI3/MainWindow.Compact.cs`
- `agentes/servidor/Arcoll.WinUI3/Assets/hybrid/dashboard/compact-shell.js`

O `MainWindow.xaml` do ajuste compacto anterior permanece. Não substitui os arquivos principais de lógica dos menus ou os scripts de dados do Painel.

## Validação

Veja **VALIDACAO_SUBMENUS_PAINEL.md** e o workflow do GitHub. A prévia é uma composição em navegador com dados de exemplo; não é captura de uma sessão Windows de produção.

A compilação de compatibilidade usa o snapshot completo disponível das entregas anteriores, com a extensão visual nova. O código completo da instalação de produção 5.0.114 não foi enviado; o identificador 5.0.114 do build não comprova diferenças adicionais de produção.

Não extraia `VALIDACAO_SUBMENUS_WINDOWS.zip` no cPanel: esse snapshot é somente para validação/compilação.

Para reverter, restaure as cópias destes dois arquivos e recompile/republique o agente.
