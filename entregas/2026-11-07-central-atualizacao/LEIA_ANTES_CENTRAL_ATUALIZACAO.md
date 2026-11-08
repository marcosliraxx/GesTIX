# Central de atualização dentro do agente Windows

O pacote adiciona o menu **Atualização** e transforma o botão superior de atualização em atalho para essa área. O acompanhamento passa a acontecer dentro do agente, com círculo percentual, animação de atividade, etapas e histórico da operação.

## Instalação pelo cPanel

1. Faça uma cópia dos arquivos substituídos, especialmente os scripts de instalação e os dois serviços de atualização.
2. Extraia **ATUALIZACAO_CENTRAL_ATUALIZACAO_CPANEL.zip** na raiz do sistema, mesclando `agentes/`.
3. Atualize/recompile o agente Windows pelo fluxo já utilizado. Se distribui uma release pré-compilada, gere e publique novamente a release com estes arquivos antes de distribuir aos clientes.
4. Reabra o agente e acesse **Atualização → Verificar e atualizar**. O botão de atualização da faixa superior também abre essa área.

O pacote é incremental sobre as entregas anteriores de Backup e layout compacto/submenus. Não altera `VERSION`, APIs, banco, credenciais ou a configuração do computador. Preserve a versão atual da sua plataforma, mostrada como 5.0.114 no último print.

### Primeira migração

O agente que já está instalado ainda contém o atualizador antigo. **A primeira atualização para instalar esta central pode continuar mostrando a janela antiga**, pois esse comportamento já está no executável em execução. Depois que a nova versão for compilada e instalada, as próximas atualizações usam a central interna. Para evitar essa última passagem pelo fluxo antigo, distribua a nova release pelo seu método administrativo existente.

## Como funciona

- **Verificar:** consulta o pacote autenticado e compara os arquivos instalados. Se já estiverem iguais, informa que estão atualizados.
- **Receber:** baixa o pacote e mostra o progresso de transferência quando o tamanho total é conhecido.
- **Validar:** mantém a conferência SHA-256 do pacote, manifesto e arquivos, além dos limites e das validações de caminhos existentes.
- **Preparar:** usa a release binária/delta quando disponível. Se não houver release publicada, continua permitindo compilar os fontes no computador, com os requisitos de SDK/NuGet do fluxo anterior.
- **Concluir:** após preparar os arquivos, o aplicador solicita a troca e espera uma confirmação correlacionada da instância do agente. Só então o agente encerra e o processo silencioso troca os arquivos, reabre e verifica a nova instância.

A preparação roda fora da thread da interface para o círculo e os controles continuarem responsivos. **O agente permanece aberto durante download, validação e preparação/compilação.** O executável não pode ficar aberto enquanto seus arquivos em uso são substituídos: há uma breve reinicialização nessa etapa final. A confirmação é retomada na mesma área quando o agente volta.

O círculo mostra progresso **por etapas**, não previsão de tempo restante. Durante uma compilação sem percentual nativo confiável, mantém o marco da etapa e anima a atividade. A porcentagem não é incrementada artificialmente com o relógio. Se os arquivos já forem iguais, 100% indica conclusão da verificação, sem reinstalação. Quando há troca de versão/arquivos, transferência terminada ou reinicialização iniciada não significam sucesso: o indicador só chega a 100% com um resultado da mesma operação confirmado pelo instalador, após os testes existentes de versão/autorização da nova instância.

## Controles

- **Verificar e atualizar:** fluxo normal.
- **Reinstalar arquivos:** solicita confirmação nativa dentro do agente antes de usar o modo forçado já existente.
- **Cancelar preparação:** disponível antes de entregar o trabalho ao instalador. Depois disso, fica indisponível para evitar interromper uma troca em andamento.
- **Atividade desta atualização:** últimas mensagens da operação atual.

Há suporte a tema claro/escuro, telas menores e preferência de movimento reduzido. Sem conexão/autorização, o botão de iniciar fica bloqueado. Falhas e cancelamentos têm estados próprios; uma falha de integridade não é exibida como atualização concluída.

## Processos e arquivos

A janela PowerShell/WPF anterior deixa de ser aberta pelos novos fluxos. O motor de instalação continua usando um processo PowerShell **oculto**, sem console ou janela visual paralela. Ele é necessário para substituir/reverter arquivos depois de o agente encerrar. Não foi introduzido prompt UAC novo nem mecanismo de elevação silenciosa; seguem as permissões do usuário e os requisitos administrativos existentes.

Arquivos de apresentação: `MainWindow.Update.cs`, `Pages/AgentUpdatePage.cs`, `Services/UpdatePresentation.cs` e `Assets/hybrid/dashboard/update.html`, `update.css`, `update.js`.

Integrações alteradas: `MainWindow.xaml`, `MainWindow.Compact.cs`, `Services/UpdateService.cs`, `Services/PrecompiledUpdateService.cs`, `INSTALAR_WINUI3.ps1`, `APLICAR_PRECOMPILADO.ps1` e o ponto de compatibilidade `ATUALIZACAO_VISUAL.ps1`.

Os recibos locais ficam em `%LOCALAPPDATA%\TI-Digital\`, incluindo `update-session.json`, `update-progress.json`, `update-handoff.json`, `update-handoff-ack.json` e os arquivos de resultado existentes. A correlação por operação evita usar o resultado de uma tentativa anterior. Não inclua essas pastas pessoais na publicação de fontes.

O pacote atualiza o agente. Os menus de atualização de aplicativos e Windows Update continuam sendo os módulos próprios já existentes.

## Validação e limites

Consulte **VALIDACAO_CENTRAL_ATUALIZACAO.md**. As imagens de prévia usam estados simulados. Não houve atualização de um servidor seu, acesso a credenciais de produção ou teste de troca do executável na sua instalação.

O snapshot de compatibilidade usa a base completa disponível das entregas anteriores, com os ajustes novos, e o identificador de build 5.0.114. O código completo da sua instalação 5.0.114 não foi enviado; o build não comprova diferenças de produção que não estejam no repositório. Comece em um computador de teste e confira o retorno do agente e o resultado na plataforma.

**Não extraia `VALIDACAO_CENTRAL_ATUALIZACAO_WINDOWS.zip` no cPanel.** Ele contém fontes de compilação e testes, não é o pacote de produção.

## Reverter

Restaure em conjunto os fontes e scripts da cópia anterior, gere/republique a release e reinstale pelo seu método administrativo. Não remova manualmente os recibos ou diretórios de staging enquanto houver um instalador em execução. Nenhum banco precisa ser revertido.
