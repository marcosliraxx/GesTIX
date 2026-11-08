# Agente Windows — rodapé e navegação compacta

## Instalar

1. Guarde uma cópia de `agentes/servidor/Arcoll.WinUI3/MainWindow.xaml`.
2. Extraia **ATUALIZACAO_AGENTE_COMPACTO_CPANEL.zip** na raiz do sistema, mesclando a pasta `agentes/`.
3. Atualize/recompile o agente Windows pelo fluxo da plataforma. O upload dos fontes no cPanel não altera o executável que já está aberto no computador. Se distribui uma release pré-compilada, gere e publique novamente essa release com os arquivos novos.
4. Reabra o agente. A logo e o nome da rede aparecem à esquerda no rodapé. Ao perder a confirmação de conexão, ambos ficam com opacidade de 42%; ao autenticar novamente, voltam à opacidade normal.

O pacote **não altera VERSION**: preserve sua versão atual, mostrada como 5.0.113 no print. Não contém banco, configuração, credenciais ou executável instalável.

## Arquivos do pacote

- `agentes/servidor/Arcoll.WinUI3/MainWindow.xaml`: estrutura visual; a marca sai da barra de categorias e passa para o rodapé. O campo de status anterior fica invisível, permanecendo disponível somente para a lógica existente da bandeja do Windows.
- `agentes/servidor/Arcoll.WinUI3/MainWindow.Compact.cs`: extensão visual em arquivo separado, incluída automaticamente pelo projeto .NET. Compacta os botões, acompanha o estado da rede, ajusta a cor do nome no rodapé e aplica a moldura sem margens às páginas locais.
- `agentes/servidor/Arcoll.WinUI3/Assets/hybrid/dashboard/compact-shell.js`: remove somente a moldura externa das páginas locais conhecidas do agente. Não altera as funções dos menus, chamadas da API ou permissões.

Não substitui `MainWindow.xaml.cs`, `MainWindow.Hybrid.cs`, o projeto `.csproj`, páginas de ferramentas ou APIs. Os nomes de controles usados pela lógica de marca e navegação foram preservados. O distribuidor existente já aceita arquivos `.cs` e recursos `.js` nesta pasta.

## Resultado

- Faixa principal: **36 px**, antes 48 px.
- Botões do submenu: **50 px**, antes 66 px; ícones de 20 px e textos de 10,5 px. O formato ícone em cima/texto embaixo e as estrelas de favoritos permanecem.
- As duas faixas continuam com rolagem horizontal independente para acomodar mais opções.
- Rodapé de 32 px com logo de 24 px e nome da rede, com truncamento para nomes longos. As informações do computador continuam à direita.
- Área de trabalho sem a margem externa de 12/10/12/12 px. A página local deixa de acrescentar a segunda faixa de margem e o painel externo fica com cantos retos e sem borda.
- Cartões de dispositivos, caixas de diálogo, campos e espaçamento interno dos controles conservam sua apresentação.

O esmaecimento segue a confirmação real de autorização do agente, incluindo a cadência atual de consulta; não foi introduzido um segundo teste de conectividade nem alterada a autenticação.

## Base e validação

O GitHub disponível nesta conversa contém a entrega-base 5.0.108; o código completo da sua instalação 5.0.113 não foi enviado. Por isso, este ajuste foi isolado em três arquivos e preserva a lógica principal. A compilação de compatibilidade usa o snapshot completo da entrega anterior com estes três arquivos, identificado no build como 5.0.113. Isso valida a integração com a base disponível, não diferenças adicionais da sua instalação que não estejam no repositório.

Veja os resultados e o link da compilação em **VALIDACAO_AGENTE_COMPACTO.md**. As duas imagens são prévias de layout renderizadas no navegador, com os recursos reais do Painel e dados de exemplo; não são capturas de uma instalação Windows de produção.

`VALIDACAO_AGENTE_COMPACTO_WINDOWS.zip` é exclusivo para compilação de compatibilidade; **não extraia esse snapshot no cPanel**.

## Reverter

Restaure o `MainWindow.xaml` guardado, remova `MainWindow.Compact.cs` e `Assets/hybrid/dashboard/compact-shell.js`, e recompile/republique o agente. Não há alteração de banco para desfazer.
