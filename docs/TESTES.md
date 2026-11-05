# Testes da versão consolidada 3.9.0

Execute somente em ambiente isolado, com contas, pastas e bancos descartáveis. Não use credenciais, arquivos ou equipamentos reais de clientes. Não gere capturas de tela.

## Python e interface nativa

`python3 -m unittest discover -s tests -v`

Em Linux, forneça Tkinter, Pillow/ImageTk e display: `xvfb-run -a python3 -m unittest discover -s tests -v`. O código canônico está em `agentes/windows` e `agentes/suporte`.

A suíte nova verifica autorização local, links, incremental, retomada, alterações durante leitura, pausa, reenvio completo, isolamento do perfil, versão e layout nativo. Suítes anteriores foram mantidas e seus caminhos atualizados para a estrutura canônica; mudanças de contrato posteriores ao código antigo são documentadas no resultado da execução.

## PHP e banco

`php tests/cloud_integration.php` exige que a configuração aponte para um banco descartável cujo nome termine em `_cloud_test`. Verifica esquema reaplicável, caminhos, SSRF, OAuth assinado, AES-GCM e limites dos relatórios.

Os testes históricos PHP/HTTP continuam exigindo seus próprios bancos descartáveis, contas sintéticas e variáveis de ambiente. Leia os guardas de segurança e instruções de cada teste antes de executá-lo. Os testes de login HTTP exigem instância local e banco com sufixo `_login_http_test`.

## Evidência da entrega

O LEIA-ME e a auditoria registram os testes executados para o ZIP: 17 verificações Python/Tkinter, 37 HTTP, 19 PHP/banco, quatro verificações do worker por HTTPS e contratos dos quatro provedores com servidores simulados, além de Chromium responsivo, formulário administrativo e instalador canônico.

Esses resultados não equivalem a testes com contas OAuth reais nem a execução do instalador/UAC em Windows. Também não afirmam homologação funcional de todos os fluxos históricos não alterados nesta atualização.

## Regressão ao atualizar o Git

Após integrar a base consolidada ao checkout anterior, os 58 testes Python executaram e passaram com Xvfb, incluindo as regressões preservadas de segurança, transferências, energia, atualização, aparência e suporte. A expectativa de transferência com hash inválido foi atualizada para conferir também a mensagem pública retornada pela implementação atual. Os temporizadores Tk dos testes são cancelados antes de destruir as janelas, para não interferirem no teste seguinte.
