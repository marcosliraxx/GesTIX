<!-- Caminho: docs/ATUALIZACAO-3.2.0.md -->
# Arcoll 3.2.0

Aplicar sobre **3.1.0**. Plataforma, agente e cliente de suporte Windows usam a mesma versão. O pacote contém somente arquivos alterados/novos e os 11 marcadores vazios de exclusão manual. Publicar no GitHub não instala no cPanel nem nos computadores.

## Instalação

1. Faça backup dos arquivos atuais e do banco de dados.
2. Extraia o ZIP. Envie os arquivos **não vazios** para a raiz do sistema, preservando exatamente as pastas. Preserve sua configuração e os uploads. Não envie `LEIA-ME-ATUALIZACAO.txt` para uma pasta pública.
3. Os 11 arquivos com **zero bytes** indicam arquivos antigos para excluir manualmente, caso ainda existam. **Não sobrescreva os arquivos do servidor com esses marcadores.** A lista está ao final das instruções do ZIP.
4. A primeira abertura autenticada aplica a migração aditiva `database/migrations/3.2.0.sql`. Ela acrescenta `payment_payload` às cobranças, para preservar o Pix emitido mesmo se a chave mudar. Não apaga tabelas, cobranças ou dados. Para aplicar manualmente, ajuste o prefixo `arc_` ao prefixo configurado no seu banco. A migração pode ser reaplicada.
5. Reabra o PWA para carregar a versão nova. No gerenciamento da rede, selecione o agente e toque em atualizar. Aguarde a confirmação do retorno do agente e confira a versão **3.2.0**. Envie também a pasta `agent` completa alterada pelo ZIP; misturar versões impede fornecer atualizações.
6. Em **Gestão comercial → Pagamentos**, confira as credenciais/webhooks dos gateways e os dados Pix: chave, nome do beneficiário e cidade. Configurações já existentes são preservadas; gateways continuam dependendo de ativação explícita. As cobranças antigas mantêm seus valores.

O arquivo `VERSION` não aceita comentários; seu caminho é `VERSION`, na raiz. Todos os arquivos de código alterados identificam o caminho no início. A biblioteca local de QR mantém sua licença MIT.

## O que mudou

- Gestão de redes: ícones acima dos nomes, divisórias e faixa com rolagem horizontal. A seção atual fica visível ao abrir. No celular, a marca do topo é a da rede; sem logo cadastrada, aparece o símbolo de rede. A marca da plataforma permanece no menu lateral. Integrantes autorizados também podem consultar a logo da rede.
- Ferramentas: desligar, reiniciar e cancelar agendamento para o computador selecionado. **Somente computadores Windows com agente aprovado e versão 3.2.0**; não desliga roteadores, impressoras ou dispositivos sem agente. Não exige digitar comandos. O gestor confirma a operação e a senha da conta. Em computadores assistidos, há autorização local; em servidores com modo sem monitor já habilitado, o gestor pode executar sem autorização local. A autorização é revalidada antes da ação para impedir execução de solicitações expiradas. O Windows recebe um agendamento de 60 segundos, sem forçar o fechamento de arquivos não salvos. Cancelar agendamento usa a ação do próprio Windows e depende de conexão e tempo disponível.
- Atualizador: mantém o protocolo e os nomes de arquivos aceitos pelos agentes antigos. A solicitação pode ficar na fila por até três minutos, sem exigir o sinal de presença de poucos segundos que bloqueava versões antigas. Corrigido o uso de `os.kill(pid, 0)` no Windows: ele podia terminar o processo em vez de somente consultar sua existência. Os agentes novos usam o atualizador recém-baixado e verificado, inclusive se o auxiliar instalado estiver ausente. Configuração e identidade do agente permanecem preservadas. A confirmação acontece após iniciar a nova aplicação; o marcador é mantido quando a plataforma não confirma o recebimento.
- Planos: catálogo compacto, detalhes/compra em modal e fluxo separado para o pagamento. A assinatura atual abre em modal. O histórico sai desta página e passa a ter arquivo próprio: `pages/cobrancas.php`, com busca, situação, período, paginação e acesso à cobrança.
- Checkout: nova página `pages/checkout.php` com resumo, valor, situação acompanhada automaticamente e continuação no checkout oficial Mercado Pago/Asaas. A liberação automática usa os webhooks/conciliação já autenticados; **retorno do navegador nunca confirma pagamento**. CPF/CNPJ inválido no Asaas é rejeitado antes de bloquear a tentativa de preparação.
- Pix manual: QR Code gerado localmente, código copia e cola, valor da cobrança e identificador. O QR foi verificado com um leitor independente. A chave não é enviada a sites de QR. “Já paguei” envia a referência para conferência; **o administrador confirma o recebimento bancário antes de liberar**. Pix manual não tem confirmação bancária automática.
- Relatórios: busca, tipo, rede, datas e ações numa única barra compacta, deslizante no celular; resultados em tabela abaixo. Redes, computadores e equipe filtram pela data de cadastro; medições/atividades pela data do registro. A impressão aplica os filtros atuais e gera A4 com cabeçalho, período, rede, busca, data de emissão e repetição dos títulos da tabela. CSV usa o mesmo filtro e protege contra fórmulas de planilha. As atividades exibem linguagem simples, preservando a auditoria original no banco. Os limites de consulta continuam informados na página.

## Agente antigo que não consegue atualizar

Teste primeiro o botão de atualização após instalar esta versão completa na plataforma. O canal foi validado com credenciais e protocolo de agentes 2.8.1; manifestos antigos continuam aceitos. Isso não exige baixar um instalador naquele computador, quando o canal e o atualizador antigo estão funcionando.

**Não é possível corrigir somente pelo servidor um agente desligado, sem comunicação, com autorização revogada ou cuja versão antiga não possua/consiga executar o atualizador.** Nesses casos, é necessário restaurar a comunicação ou usar uma sessão remota autorizada para reparar a instalação. Não foi acessado nem atualizado o servidor específico mencionado pelo usuário. O teste de compatibilidade de protocolo não equivale a uma instalação real no Windows desse servidor.

## Validação

- 232 combinações de página, tema e tamanho de tela sem falhas.
- 39 cenários de inventário populado, mapa, planos, login e modais preservados.
- 45 verificações HTTP/navegador: checkout, leitura real do QR, isolamento de contas, Pix em conferência antes da aprovação, histórico, relatório/PDF A4, menu horizontal, modal de energia, protocolo de atualização antigo e permissões/revalidação de energia. Comandos Windows e pagamentos bancários não foram executados nesses testes.
- 41 verificações de serviços, cobrança, quotas, acesso remoto, checksum/snapshot Pix e reaplicação da migração preservando cobranças.
- 31 testes Python: interface, segurança, versão, cliente de suporte, ferramentas, argumentos de energia, integridade e compatibilidade de manifestos antigos. Chamadas de desligamento/reinicialização foram simuladas; nenhum equipamento foi desligado.
- Sintaxe PHP/JavaScript/Python, análise dos CSS e integridade do ZIP verificadas.

Não foram testados: pagamentos reais ou sandbox dos gateways, leitura pelo aplicativo de um banco, instalação física no Windows, atualização efetiva do agente específico do usuário e aparelhos iPhone/Android reais. Os testes mobile usaram navegador Chromium com tamanhos de tela representativos. Para pagamentos, conclua a configuração e faça uma cobrança de teste antes de vender planos.

## Desfazer

Restaure os arquivos da versão 3.1.0 a partir do backup. A coluna adicional do banco pode permanecer; não remova cobranças nem restaure um banco antigo sobre pagamentos novos sem conciliar. A atualização do agente mantém backup dos arquivos alterados na pasta de preparação local; preserve a configuração/identidade ao restaurar. Se uma atualização falhar, confira o retorno na plataforma e o registro `%LOCALAPPDATA%\Arcoll\update-error.log`.
