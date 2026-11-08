# DWService e evolução do acesso remoto T.I. Digital

Pesquisa técnica • 8 de novembro de 2026 • Base analisada: backup 5.0.151 e entrega 5.0.152.

## Resultado da análise

O DWService combina conexão de saída permanente, servidores de retransmissão, captura nativa, codificação adaptativa e permissões por aplicação. Sua boa experiência decorre desse conjunto. Uma interface bonita, isoladamente, não resolve atraso de imagem, fila de comandos ou saturação do servidor.

O seu sistema já possui sessões autorizadas, revogação, identificação por rede, perfis de qualidade e uma implementação Python mais avançada que a implementação C#. A prioridade recomendada é unificar essas capacidades e medir os gargalos antes de substituir o transporte. Este documento é uma análise e proposta técnica; a entrega 5.0.152 altera instalação, logs e temas, sem substituir o motor remoto por DWService.

## Método e limites

Foram consultadas documentação oficial, documentação da API e fontes públicas do agente DWService. O código examinado foi fixado no commit `18d14aaa1d87634d40604078471b688e3f27f80d`, disponível no [repositório oficial](https://github.com/dwservice/agent/tree/18d14aaa1d87634d40604078471b688e3f27f80d). As referências de arquivos abaixo usam esse commit para permitir conferência posterior.

“Documentado” significa declaração do fornecedor; “observado no código” significa comportamento identificável na revisão consultada; “proposto” significa recomendação para o seu produto. Não houve benchmark simultâneo em máquinas Windows reais nem acesso ao código privado do servidor DWService. A publicação do agente não comprova que todo o serviço hospedado esteja disponível para instalação própria.

## Arquitetura e conexão

Segundo a [documentação de segurança](https://www.dwservice.net/en/security.html), o portal autentica e encaminha conexões, o backend administra os dados e os nós intermedeiam sessões. O agente mantém conexão com um nó. A comunicação utiliza HTTPS/TLS na porta TCP 443. A mesma página declara armazenar dados de conta, agentes, compartilhamentos e registros de acesso, mas não o conteúdo em trânsito. Ela também descreve TOTP e dispositivos confiáveis.

Fluxo conceitual documentado:

```text
Técnico / navegador ── HTTPS 443 ── nó de sessão ── conexão do agente ── computador
            │                           │
            └── portal e autorização ─── backend de gestão
```

A [orientação oficial de firewall](https://docs.dwservice.net/docs/site/basic-definitions/firewall/) explica a liberação de saída para os domínios do serviço. Isso facilita atravessar NAT sem publicar uma porta na máquina do cliente. Um proxy corporativo ainda pode exigir configuração; 443 não significa autorização automática em toda rede.

No agente, [core/communication.py](https://github.com/dwservice/agent/blob/18d14aaa1d87634d40604078471b688e3f27f80d/core/communication.py) contém transporte TLS e suporte a proxies HTTP CONNECT e SOCKS. Existem caminhos de compatibilidade antigos: não devem ser copiados como política criptográfica de um projeto novo. [core/agent.py](https://github.com/dwservice/agent/blob/18d14aaa1d87634d40604078471b688e3f27f80d/core/agent.py) concentra ciclo de vida, comunicação e carregamento de aplicações.

TLS documentado não demonstra, sozinho, criptografia de ponta a ponta em que o retransmissor seja incapaz de ler dados. A documentação pública consultada não permite afirmar essa propriedade. Também apresenta um nó simplificado para hospedagem própria como plano, não como um pacote público completo já comprovado nesta pesquisa.

## Como a imagem é produzida e transmitida

Em [app_desktop/desktop.py](https://github.com/dwservice/agent/blob/18d14aaa1d87634d40604078471b688e3f27f80d/app_desktop/desktop.py), a aplicação coordena sessões, captura, codificação, entrada, áudio e retorno de estatísticas. Há separação entre processos e comunicação interna. [encoder.py](https://github.com/dwservice/agent/blob/18d14aaa1d87634d40604078471b688e3f27f80d/app_desktop/encoder.py) integra bibliotecas nativas: não se trata de processar cada pixel em laços Python.

No Windows, o [backend Desktop Duplication](https://github.com/dwservice/agent/blob/18d14aaa1d87634d40604078471b688e3f27f80d/lib_screencapture/src/desktopduplication/screencapturenativedesktopduplication.cpp) usa aquisição de quadros, regiões alteradas, regiões movidas e informação do cursor. O [codificador nativo](https://github.com/dwservice/agent/blob/18d14aaa1d87634d40604078471b688e3f27f80d/lib_screencapture/src/main.cpp) contém codificação TurboJPEG e divisão da saída em partes. Isso é evidência concreta de um pipeline mais elaborado que reenviar um JPEG completo a cada consulta HTTP.

A [Microsoft descreve Desktop Duplication](https://learn.microsoft.com/en-us/windows/win32/direct3ddxgi/desktop-dup-api) como acesso aos quadros por superfícies DXGI e metadados das regiões alteradas ou movidas. O receptor precisa reconstruir corretamente a imagem; rotação e cursor exigem tratamento próprio. Portanto, mudar de GDI para DXGI é um trabalho de captura, protocolo e reconstrução, não apenas trocar uma função.

A [documentação de conexão do DWService](https://docs.dwservice.net/docs/site/basic-definitions/connection-info/) identifica TJPEG_V2 como codificador padrão e qualidade adaptativa de 0 a 9. Diferencia FPS exibido, FPS capturado, banda, atraso de quadros e ping. Tela parada pode produzir zero quadros sem indicar falha. Essas métricas ajudam a distinguir captura lenta, rede congestionada e navegador atrasado.

O código recebe confirmações e tempos do cliente e controla distância entre quadros. A consequência prática dessa arquitetura é evitar acumular imagem antiga indefinidamente. Para suporte técnico, uma imagem recente com qualidade moderada costuma ser mais útil que uma imagem perfeita de vários segundos atrás. Essa última frase é uma decisão de engenharia recomendada para o seu produto, não um benchmark do fornecedor.

## Funcionalidades e permissões

O [catálogo oficial de aplicações](https://www.dwservice.net/en/applications.html) inclui tela, arquivos e pastas, recursos, shell, editor de texto e acompanhamento de logs. Essa divisão permite resolver tarefas sem sempre abrir uma sessão gráfica.

A [aplicação de tela](https://docs.dwservice.net/docs/site/basic-definitions/screen-application/) oferece seleção de monitor, escala, qualidade, áudio, área de transferência e combinações de teclas, conforme ambiente e permissão. O controle de teclado e mouse é diferente da autorização para apenas visualizar. Áudio e arquivos também devem ser capacidades independentes.

A implementação de [shell](https://github.com/dwservice/agent/blob/18d14aaa1d87634d40604078471b688e3f27f80d/app_shell/shell.py) possui integração específica por sistema. O módulo [ConPTY](https://github.com/dwservice/agent/blob/18d14aaa1d87634d40604078471b688e3f27f80d/app_shell/os_win_pyconpty/conpty.py) evidencia suporte a terminal Windows interativo, que é diferente de executar um comando isolado e devolver seu texto.

Para seu sistema, a autorização deve sempre vir do servidor e ser conferida no gestor. Ocultar um botão na interface não limita o acesso. Acesso sem acompanhamento local exige política administrativa explícita por cliente, registro e revogação; não deve surgir como efeito colateral de instalar um serviço.

## Integração oficial por API

A [introdução da API](https://docs.dwservice.net/docs/api/getting-started/) descreve integração REST/JSON por HTTPS e abertura de sessões. A [página comercial da API](https://api.dwservice.net/en/home.html) diferencia inventário de agentes e canais simultâneos. Uma quantidade de computadores cadastrados não equivale à mesma quantidade de técnicos conectados ao mesmo tempo. Conta e condições comerciais da API devem ser verificadas separadamente do uso comum do portal.

A [autenticação publicada](https://docs.dwservice.net/docs/api/getting-started/authentication/) usa Key e Secret; descreve Basic e o esquema HMAC HM1. O contrato atual de HM1 utiliza SHA-1 e timestamp. Se integrar o serviço, seguir o contrato documentado no backend; isso não é recomendação para inventar um protocolo próprio novo com SHA-1. Segredos da API nunca devem ser enviados para JavaScript, iframe ou gestor de um cliente.

A [criação de sessão](https://docs.dwservice.net/docs/api/sessions-resource/create-a-new-session/) admite canal, agente e permissões por aplicação, incluindo controle de tela, áudio e caminhos de arquivos. Recomenda-se solicitar `fullAccess=false` e conceder somente as capacidades necessárias. O servidor do seu sistema deve verificar conta, rede, função e identidade do técnico antes de criar cada sessão.

A [integração por iframe](https://docs.dwservice.net/docs/api/develop-the-web-application/open-iframe-session/) oferece eventos de conexão e utiliza origem de mensagens. A aplicação precisa validar origem e remetente de `postMessage`, limitar o destino do iframe e fechar sessões ao revogar acesso. Não se deve simplesmente esconder a interface do fornecedor e compartilhar uma credencial única entre todos os clientes.

A [personalização do instalador Windows](https://docs.dwservice.net/docs/api/customize-the-installer/windows/) prevê identidade visual e recomenda assinatura do executável. Isso demonstra que aparência profissional e confiança de distribuição são assuntos complementares. O novo instalador T.I. Digital desta entrega é próprio; não contém DWAgent nem configura conta DWService.

## Comparação com o seu sistema atual

Esta tabela descreve código da base recebida, não testes físicos de desempenho.

| Área | Gestor C# atual | Gestor Python atual | Evolução recomendada |
|---|---|---|---|
| Captura | GDI, monitor principal | Seleção e metadados de monitores | Contrato único de monitores, DPI e coordenadas |
| Imagem | JPEG completo, supressão de quadros iguais | JPEG completo, assinatura para evitar reenvio ocioso | Regiões alteradas e recuperação por quadro completo |
| Qualidade | Perfis low/balanced/high, limite de 300.000 bytes | Perfis com resolução e orçamento de banda | Adaptação por atraso e fila, além de resolução |
| Transporte | POST de imagem Base64 e consulta de entrada sequenciais | Captura e entrada em threads separadas | Canal persistente binário com prioridade para entrada |
| Desktop seguro | Explicitamente não suportado nesse serviço C# | Existe integração com helper específico | Padronizar política e testes de privilégios |
| Permissão | Sessão consentida, revogação e limite de 30 minutos | Controles próprios por sessão e helper | Capacidades consistentes nos dois caminhos |

Arquivos locais relevantes: `gestores/servidor/Arcoll.WinUI3/Services/RemoteScreenService.cs`, `gestores/windows/arcoll_remote.py`, `gestores/windows/arcoll_secure_helper.py` e `api/remote-screen.php`.

No C# recebido, a pausa após um ciclo é de 350 ms quando a imagem muda e 700 ms quando fica estável. Apenas a pausa de 350 ms já impõe teto teórico inferior a aproximadamente 2,86 ciclos por segundo, antes de captura, codificação e duas viagens HTTP. Isso não é FPS medido. O perfil balanced limita a imagem a 1280×800; high permite até 1600×1000, conservando proporção. A supressão de imagens idênticas e o heartbeat de três segundos já são melhorias presentes na sua atualização e devem ser preservadas.

No Python, há intervalos alvo de 0,1 s durante controle ativo, 0,15 s em outro controle e 0,5 s em visualização. O orçamento por perfil e o tempo de envio podem aumentar esses intervalos. Portanto, não se deve prometer dez FPS apenas lendo a constante. O código já trata escolha de monitor, qualidade e imagem ociosa; seria desperdício recomeçar sem aproveitar esses contratos.

Base64 ocupa aproximadamente quatro bytes para cada três bytes binários. Um JPEG de 300.000 bytes vira cerca de 400.000 bytes antes do envelope JSON. Essa conta explica uma oportunidade real de redução de tráfego, mas não representa o tamanho médio observado nas suas máquinas.

O relay PHP atual preserva o último JPEG e uma fila de entrada em arquivos. Esse desenho é compreensível no cPanel e adequado como fallback, mas escala de maneira diferente de nós persistentes dedicados. Requisições longas podem consumir trabalhadores PHP; compartilhar o mesmo pool com administração, inventário e backups aumenta competição de recursos.

## Arquitetura proposta para T.I. Digital

Manter PHP e banco como plano de controle: contas, redes, dispositivos, permissões, autorização e auditoria. Adicionar, quando a etapa de desempenho for autorizada, um relay dedicado com conexões persistentes na porta 443. O cPanel permanece responsável pela aplicação; o relay pode operar em VPS/container com TLS e limites por cliente.

Proposta de fluxo:

```text
Plataforma PHP ── autorização curta e restrita ── técnico e gestor
Técnico ── WSS/binário ── relay de mídia ── WSS/binário ── gestor
                             │
                       métricas por sessão
```

A autorização proposta deve incluir conta, rede, dispositivo, sessão, capacidades, expiração e identificador de uso. Vincular as duas pontas ao mesmo escopo. Reconexão deve renovar autorização; revogação deve invalidar o relay também. A implementação criptográfica deve usar bibliotecas e padrões estabelecidos, sem criar cifra própria.

Separar fluxos de vídeo, entrada, arquivos e áudio. Usar filas limitadas e confirmação de quadros; descartar imagem obsoleta quando necessário e priorizar entrada. Transferência de arquivo deve ter partes, retomada, hash e limites de caminho, sem bloquear teclado e mouse. Usar processos separados para captura e serviço privilegiado, com IPC autenticado e operações estritamente delimitadas. Não alterar UAC para tornar o controle possível.

Definir versões de protocolo e capacidades negociadas para permitir atualização gradual. Uma máquina antiga deve continuar usando o fallback HTTP até ser atualizada. A troca deve ter feature flag e retorno automático, sem apagar o acesso remoto já funcional.

## Plano técnico por etapas

1. **Inventário e medição:** identificar qual caminho remoto cada gestor executa, registrar latência de entrada, captura, codificação, bytes, fila e erros. Reproduzir sessões de referência com consentimento. Unificar permissões e recursos de monitores antes de prometer desempenho equivalente.
2. **Protótipo de transporte:** construir relay persistente isolado, imagem binária e prioridade de entrada; manter PHP para autorização e fallback. Testar revogação, troca de conta e reconexão antes de habilitar para clientes.
3. **Captura eficiente:** backend DXGI com fallback GDI, cursor separado, regiões alteradas, keyframes e controle de pressão da fila. Medir uso de CPU/GPU e consumo de banda nas mesmas cenas.
4. **Ferramentas de suporte:** terminal ConPTY, arquivos com retomada, recursos e log watch, cada um com permissão e auditoria. Integrar visualmente às Atividades existentes, sem transformar diagnóstico central em gravação indiscriminada da tela.
5. **Decisão construir ou integrar:** comparar custo, cobertura Windows, requisitos de privacidade e manutenção com um protótipo da API DWService. Integrar não exige copiar o agente nem reimplementar imediatamente todos os módulos.

Não há estimativa fechada de prazo sem acesso ao ambiente de implantação e medições. Nenhuma dessas fases futuras está anunciada como já entregue na versão 5.0.152.

## Validação e operação propostas

Comparar LAN, internet comum, conexão limitada a 1 Mbps, RTT alto e perda. Usar cenas de texto, rolagem, vídeo e tela ociosa. Medir percentis de latência, quadros exibidos, backlog, memória e bytes; distinguir objetivos de produto de resultados efetivamente medidos.

No Windows, incluir múltiplos monitores, coordenadas negativas, DPI diferentes, rotação, GPUs diferentes, mudança de resolução, bloqueio, UAC, máquina sem monitor e reinício. Validar desconexão, expiração e revogação durante cada operação. Testes entre clientes devem comprovar que uma sessão nunca recebe imagem, entrada ou arquivo de outra rede.

Exemplo de dimensionamento, inteiramente hipotético: dez sessões médias de 2 Mbps, oito horas por dia e vinte dias equivalem a aproximadamente 1,44 TB de saída de vídeo por mês. Um relay também recebe aproximadamente esse volume. Não inclui áudio, arquivos ou overhead. Capacidade de armazenamento no Google Drive não substitui banda de relay nem orçamento de tráfego.

## Licenças e conclusão técnica

Os arquivos LICENSE do núcleo, app_desktop e biblioteca de captura consultados declaram MPL 2.0. Componentes incorporados possuem licenças próprias e precisam ser inventariados antes de distribuir qualquer derivação. A licença do agente não concede automaticamente direito de copiar marca, infraestrutura hospedada ou condições comerciais da API.

A recomendação para o seu sistema é aproveitar a atualização que você enviou, eliminar diferenças entre os caminhos C# e Python e evoluir transporte e captura com métricas. O estudo oferece um roteiro verificável para essa próxima etapa; a entrega atual mantém seu acesso remoto funcionando e prepara instalação, diagnóstico e identidade visual.
