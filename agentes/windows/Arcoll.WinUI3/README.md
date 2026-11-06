<!-- Caminho: agentes/windows/Arcoll.WinUI3/README.md -->
# T.I Digital 5.0.0 — agente WinUI 3 em C# (homologação)

Este código-fonte estende a migração em andamento do agente Windows para WinUI 3/.NET 8. **Ainda não substitui integralmente os módulos Python antigos**. As páginas nativas Servidor e Monitoramento anteriores foram preservadas sem alteração. Não remova o Python nem publique em produção sem homologação funcional.

## Funcionalidades C# disponíveis no código

Autorização e presença na API da plataforma, telemetria, bandeja do Windows, páginas Servidor/Monitoramento, Velocidade, Dispositivos, Mapa, Programas, Clean básico, Ferramentas, Conexão, tema da plataforma e atualização remota/forçada. Nesta entrega foram adicionadas a página e o executor nativo do Windows Update (COM), os comandos de Windows Update pela API e a listagem remota de discos/pastas para explorador/seleção de nuvem (somente leitura). A versão declarada foi alinhada em `5.0.0`.

## Compilação/instalação

Windows 10 (build 19041+) ou Windows 11, .NET SDK 8, Microsoft Windows App SDK 1.6 e ferramentas MSBuild/WinUI. No PowerShell: `./Compilar-WinUI3.ps1` para compilação ou `./INSTALAR_WINUI3.ps1` para instalar a partir das fontes. O fluxo da plataforma `api/download-agent.php?mode=native-bootstrap` entrega o instalador visual autenticado.

O instalador cria atalhos no menu Iniciar e na Área de Trabalho e configura inicialização por usuário. O atualizador conserva uma cópia da instalação anterior e tenta reverter se a substituição ou a inicialização falhar. Logs ficam em `%LOCALAPPDATA%\TI-Digital\Logs` e o progresso local de atualização em `%LOCALAPPDATA%\TI-Digital\update-progress.json`.

## Limites conhecidos

Ainda no Python: acesso remoto com vídeo/controle/consentimento, transferência e operações de arquivos, backup em nuvem, unidade virtual, Clean avançado e parte das ações sobre programas. **Não existe equivalência funcional total nem garantia de compilação**: o ambiente de criação deste pacote não tem Windows/.NET SDK. Os percentuais de instalação são marcos do fluxo e não progresso em bytes do compilador. Valide WinUI/XAML e COM, testes reais de instalação/rollback, comportamento de sessão e cargas antes do deploy.

Consulte `docs/AUDITORIA-5.0.0.md` para todas as diferenças, verificações e pendências.
