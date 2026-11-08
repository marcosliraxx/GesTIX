# Validação — agente compacto

[Compilação Windows aprovada](https://github.com/marcosliraxx/GesTI/actions/runs/42436789216).

- Agente completo WinUI 3 x64 compilado com a extensão visual: **0 avisos e 0 erros**.
- **12 verificações estruturais**: posição da logo/nome, ausência da marca na faixa principal, status textual invisível, opacidade inicial, dimensões, margens externas e pacote restrito a três arquivos.
- **26 verificações no navegador**: recursos reais de Painel, Internet, Pente-Fino e Backup; conteúdo desde a origem da área, largura total, moldura externa sem borda/arredondamento, ausência da margem do body, carregamento sob as políticas CSP existentes e funcionamento da consulta/diálogo de dispositivos com ponte emulada. Também foi conferida a prévia composta do rodapé e a largura de 480 px.
- JavaScript do ajuste validado e ZIP conferido por CRC.

A compilação usou o snapshot completo disponível da entrega anterior, com estes três arquivos, e o identificador de build 5.0.113. O código completo de produção 5.0.113 mostrado no print não foi disponibilizado. O pacote não altera `VERSION`, os arquivos de lógica principal ou as APIs.

As imagens mostram uma **prévia composta em navegador**, com as dimensões do novo shell e os recursos reais da página Painel, usando dados de exemplo. Não são capturas do aplicativo WinUI em execução. A sessão gráfica Windows, os cliques reais da navegação nativa e a transição de conexão devem ser conferidos após recompilar e abrir o agente na instalação.

O snapshot `VALIDACAO_AGENTE_COMPACTO_WINDOWS.zip` é para compilação/inspeção, não para instalar no cPanel. Use o arquivo `ATUALIZACAO_AGENTE_COMPACTO_CPANEL.zip`.
