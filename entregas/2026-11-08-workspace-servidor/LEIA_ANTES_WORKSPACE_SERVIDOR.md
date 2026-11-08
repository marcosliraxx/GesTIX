# Agente Windows 5.0.118 — workspace e componentes

Aplique esta atualização sobre a instalação atual, com as entregas anteriores de Backup, Pente-Fino, moldura compacta, temas e histórico de atualizações já integradas. Os arquivos da raiz antiga do repositório não representam essa base; use a pasta desta entrega.

## Atualizar pelo cPanel

1. Faça uma cópia dos arquivos que serão substituídos.
2. Envie **ATUALIZACAO_WORKSPACE_SERVIDOR_CPANEL.zip** para a raiz do site (a pasta que contém `VERSION`, `api` e `agentes`) e extraia, substituindo os arquivos correspondentes. O ZIP já contém os caminhos corretos, sem uma pasta extra por fora.
3. No agente, clique no ícone de atualização à direita, verifique e atualize. Ao concluir, o rodapé deve mostrar **5.0.118**.

Este pacote contém fontes; alterações C# precisam chegar ao executável. Se existir uma release binária anterior a 5.0.118, a API passa a oferecer o pacote de fontes autenticado para o fluxo de compilação existente. Essa preparação pode demorar e exige o ambiente .NET utilizado pelo instalador atual. Para atualização sem compilação no cliente, gere e publique uma release 5.0.118 ou superior pelo `PUBLICAR_RELEASE_PRECOMPILADA.ps1` já existente, para cada arquitetura utilizada. O arquivo `MINIMUM_RELEASE_VERSION` controla esse requisito específico do agente, sem amarrá-lo a futuras alterações apenas do PHP.

Não substitua `agent-config.json`, credenciais, bancos ou a instalação inteira pelo snapshot de validação. Nenhum desses dados integra o ZIP de atualização. Não há migração de banco nesta entrega.

## O que mudou

- Painel: coluna **Atividades**, recolhida inicialmente, com botão **!** na barra. Mantém os eventos reais de entrada e saída, identificação e horários.
- Moldura comum nas páginas do agente: uma barra horizontal com os controles próprios da página e o botão Atividades; conteúdo separado abaixo; informações de contexto no rodapé principal. Barras compridas rolam horizontalmente para manter uma única linha.
- Atividades guarda até 100 eventos da sessão nas demais páginas; históricos permanentes de backup, correções e atualizações continuam nas áreas correspondentes. A preferência de abertura é mantida por página WebView; nas páginas nativas permanece enquanto a página existe.
- Monitoramento saiu do submenu; seus indicadores foram incorporados ao Servidor. Atalhos legados para `monitor` abrem Servidor. Não há botões Relatório ou Varrer rede nessa tela.
- Ordem do Servidor: **Internet → CPU → Memória → discos físicos → GPUs → interfaces de rede**.
- Intervalos: Contínuo, 5, 10, 30, 60, 120 segundos e Pausar. A escolha altera a coleta. Gráficos respeitam os horários reais, preservam lacunas e não inventam pontos durante pausa. Há busca, seleção de séries e estilos Área/Linhas/Barras.
- Cada disco físico agrupa seus volumes; atividade, resposta e transferências substituem a antiga ocupação de volume como gráfico de desempenho. Cada GPU é identificada pelo LUID do DXGI, com motores distintos, memória e driver; cada interface possui taxas e endereços próprios.
- As cores seguem as áreas e o tema administrados na plataforma. O rodapé mantém logo/rede, contexto da página e identificação da máquina/versão.

## Como as medições funcionam

- CPU: GetSystemTimes; frequência e inventário WMI; processos/threads/identificadores pelo Windows; caches pela topologia nativa.
- Memória: memória física em uso/disponível e composição correspondente; commit, cache e pools do provedor Windows; módulos, velocidade e slots do inventário.
- Discos: associação física disco → partição → volume; contadores Windows de atividade e transferência. Resposta calculada pela diferença do contador bruto/base/frequência, não por porcentagem de ocupação.
- GPU: DXGI, teste do nível de recursos DirectX, contadores Windows por adaptador e motor, e temperatura pelo D3DKMT quando suportada pelo driver WDDM. Utilização é a do motor mais ocupado; percentuais de motores diferentes não são somados. Não é criado gráfico de memória dedicada para adaptador sem essa memória. A arquitetura UMA é consultada pelo Direct3D 12 para não tratar a reserva de RAM de uma GPU integrada como VRAM dedicada.
- Internet: latência e perda ICMP para 1.1.1.1, estado da resposta, dispositivos descobertos, download/upload do adaptador da rota de saída. Os gráficos têm unidades separadas. Tráfego inclui a rede local; não é teste da velocidade contratada. Ausência de ICMP não comprova queda.
- Rede: diferença de bytes por adaptador dividida pelo intervalo real, sem somar interfaces e duplicar tráfego.

O inventário de hardware é reavaliado a cada dois minutos. A coleta de desempenho é sequencial, sem sobreposição; um provedor lento pode estender o intervalo. A descoberta de rede também é sequencial, com espera igual ao intervalo escolhido depois de concluir a varredura; no modo contínuo, a espera mínima da descoberta é de cinco segundos para evitar varreduras incessantes. Seu horário aparece no rodapé. Pausar ou sair do Servidor interrompe a coleta e descoberta próprias dessa página; não desliga os serviços e a comunicação de fundo do agente.

**Indisponível** significa que o provedor não entregou o dado. Compressão da memória, reserva da GPU e certos detalhes de drivers não são expostos de modo uniforme pelas APIs utilizadas. A temperatura é consultada pelo D3DKMT; drivers sem esse suporte permanecem com o campo indisponível. Esses campos permanecem identificados, sem valores fictícios ou instalação de drivers adicionais. Em discos sem operações entre duas leituras, a resposta média fica indisponível. Formatação e ocupação não são confundidas com atividade.

## Validação e primeira conferência

As prévias são composições da interface com dados de exemplo, identificadas na imagem. O relatório de validação registra a compilação WinUI e a coleta real em um Windows de teste; esse ambiente não reproduz as GPUs/discos específicos de cada cliente.

Após atualizar um computador, confira o botão !, troca entre páginas, rodapé, pausa/retomada, identificação de discos com mais de um volume e GPUs reais. Campos dependentes do driver devem ser comparados no próprio equipamento. Os testes não executam atualização, restauração ou limpeza em máquinas de clientes.
