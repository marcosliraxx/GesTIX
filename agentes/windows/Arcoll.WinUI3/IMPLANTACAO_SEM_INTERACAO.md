# Servidores sem intervencao local

Regra da plataforma existente, verificada nesta entrega: com headless_mode ativo no computador aprovado, proprietario (owner) ou administrador (administrator) da rede pode iniciar as operacoes sem dialogo de consentimento do agente. O servidor valida rede, conta, solicitante e comando. Acesso remoto revalida a permissao por agent-remote-authorize; demais operacoes administrativas usam suas respectivas verificacoes. Operadores e visitantes nao recebem essa dispensa. Sem monitor significa a opcao configurada na plataforma, nao simplesmente desconectar um cabo de video.

## Implantacao sem janelas de permissao

Acrescentado parametro -Unattended ao instalador nativo. Execute por ferramenta de implantacao de TI em contexto administrativo ja provisionado:

    powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File .\INSTALAR_WINUI3.ps1 -Unattended

Sem privilegios administrativos, retorna codigo 5 ANTES de alterar a instalacao e sem abrir UAC. Com privilegios, configura a tarefa de boot diretamente. O parametro nao desativa o UAC do sistema nem transforma o perfil online em administrador Windows. O pacote ainda exige SDK .NET 8, conectividade de compilacao e configuracao do agente.

A interface e instalada no perfil da conta que executa o instalador. Planeje esse perfil na implantacao; executar como SYSTEM nao instala a interface automaticamente nos perfis de outros usuarios. O componente de presenca de boot fica em Program Files e roda como SYSTEM sem login.

## Limite atual

O worker de boot fornece apenas presenca. Comandos completos e acesso remoto do agente WinUI continuam dependentes de uma sessao Windows. Para operar esses recursos tambem sem usuario conectado, ainda e necessario implementar um servico operacional e, para imagem/entrada, um componente de sessao separado. Esta entrega nao implementa esses componentes nem promete controle remoto completo antes do login.

Validacao nesta entrega: revisao dos caminhos de autorizacao online, verificacao estatica do parametro e integridade dos ZIPs. Sem execucao de PowerShell/WinUI ou reinicializacao real em Windows neste ambiente Linux.
