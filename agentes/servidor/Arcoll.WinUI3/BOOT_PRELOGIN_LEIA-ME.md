<!-- Caminho: agentes/servidor/Arcoll.WinUI3/BOOT_PRELOGIN_LEIA-ME.md -->
# Inicialização antes do login — correção

O instalador INSTALAR_WINUI3.ps1 agora solicita a autorização administrativa do Windows (UAC) para instalar a tarefa de boot. Aceite essa autorização. Na versão 5.0.21, uma falha nessa etapa gera pendência explícita e diagnóstico próprio, sem desfazer o aplicativo. Atualizações normais e forçadas sem elevação não abrem UAC; preservam o componente de boot anterior e informam que sua configuração precisa de implantação administrativa. A interface continua instalada no perfil original.

O componente TIDigital.BootPresence.exe é publicado com o runtime .NET incluído, copiado para Program Files com acesso restrito e executado por SYSTEM no evento de inicialização do Windows. Não depende de usuário conectado. A tarefa permite bateria, não tem limite de duração e reinicia em falhas.

Também foram corrigidos o fingerprint ausente no pacote inicial e a resolução da configuração do perfil original antes da elevação. Uma resposta 401/403 passa a ser tentada novamente a cada 60 segundos, permitindo aprovação posterior sem reiniciar a tarefa. O servidor mantém suas verificações de autorização.

## Aplicação

1. Substitua os arquivos do servidor pelos arquivos deste pacote, preservando as pastas. Não é necessário importar SQL.
2. No painel, baixe novamente o agente para o computador e execute o instalador. Aceite o UAC da etapa de inicialização antes do login. O instalador compila os executáveis no Windows.
3. Para reparar uma instalação já compilada com esta versão: execute HABILITAR_PRELOGIN.ps1 como administrador, passando -AgentFolder com o caminho completo da instalação do usuário (normalmente C:\Users\USUARIO\AppData\Local\TI-Digital\WinUI3). Não use a pasta de outro perfil.
4. Execute VERIFICAR_PRELOGIN.ps1 como administrador. Reinicie o computador, aguarde pelo menos 60 segundos sem fazer login e confira a presença no painel por outro computador. Depois consulte %ProgramData%\TI-Digital\Boot\presence.log: deve haver uma entrada anterior ao login com Estado: autenticado.

## Escopo e validação

Antes do login, este componente informa presença na plataforma para um agente previamente aprovado. A interface WinUI, captura da tela e os comandos completos continuam dependendo da sessão do Windows. Esta correção não converte a interface em um serviço nem implementa essas operações antes do login.

Os arquivos enviados são fontes e scripts atualizados; não há novo executável Windows pré-compilado. A validação automatizada Windows está em .github/workflows/windows-installer.yml; consulte seu resultado no GitHub Actions. Foram verificadas a estrutura do pacote e as alterações nos scripts. A compilação WinUI e o teste real de reinicialização precisam ser realizados no Windows; não foram executados no ambiente Linux usado nesta entrega.

Para desativar a tarefa, execute HABILITAR_PRELOGIN.ps1 -Disable como administrador.
