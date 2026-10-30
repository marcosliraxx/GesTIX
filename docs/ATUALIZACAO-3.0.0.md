# Arcoll 3.0.0

A atualização mantém o painel compacto e os temas da versão anterior, acrescenta atendimento e comercialização por conta e mantém o acesso remoto pelo navegador. O cliente Windows é um novo visualizador Tkinter; ele compartilha a autorização, a sessão e a fila de entrada do agente existente. Não é uma página web empacotada.

## Instalação no cPanel

1. Faça backup dos arquivos e do banco. Ative a manutenção da plataforma durante o envio.
2. Extraia o ZIP no computador e envie somente os arquivos não vazios, nas mesmas pastas da raiz que contém `index.php`. Envie primeiro os novos módulos, a pasta `support` e `database/migrations/3.0.0.sql`; envie `includes/bootstrap.php` por último.
3. Os 11 arquivos vazios são marcadores de implementações antigas já retiradas. Não os envie para sobrescrever arquivos. Exclua manualmente os caminhos listados no LEIA-ME, se ainda existirem no servidor. As pastas e arquivos não listados devem ser preservados.
4. A rotina original de schema executa a migração aditiva automaticamente. Caso o usuário MySQL não tenha permissão de criação, importe `database/migrations/3.0.0.sql` pelo phpMyAdmin após o backup. O prefixo padrão é `arc_`; ajuste-o se sua instalação usa outro. Não importe `database/schema.sql` no banco em uso: é uma referência de instalação nova.
5. Requisitos do servidor: PHP 8.1 ou superior, PDO MySQL, mbstring, OpenSSL, cURL e ZIP. Preserve `includes/config.php`, `app_secret`, uploads e armazenamento. Não substitua credenciais pelo modelo de configuração.
6. Reabra o PWA/recarregue o navegador. Confira a versão 3.0.0 e as novas entradas do menu. Atualize os agentes pelo instalador gerado na plataforma, fechando a versão antiga e preservando configuração/histórico. Plataforma, agente e cliente de suporte possuem a mesma versão.

GitHub e ZIP não fazem deploy no cPanel nem atualizam computadores automaticamente.

## Suporte e equipe

- A página Suporte de cada rede oferece chamados com prioridade, prazo/SLA, responsável, notas técnicas, registro manual de minutos, encerramento com solução e histórico de eventos. A fila tem filtros e paginação.
- Atendimentos e equipe agrega as redes autorizadas, participação dos técnicos nos últimos 30 dias, minutos registrados e últimas sessões. Administradores veem o panorama; o acesso ao computador continua condicionado à conta/rede autorizada.
- O proprietário gerencia membros no modal de gestão da rede já existente. Operadores podem atender e assumir um chamado; apenas proprietário/administrador da rede pode atribuí-lo a outro técnico. Visualizadores não alteram chamados nem iniciam sessões remotas.
- Convites pendentes são efetivados pelo callback Google com e-mail verificado; removemos a aceitação paralela por simples correspondência de e-mail no painel. Vínculos já existentes são preservados.
- Os indicadores usam eventos efetivamente registrados por cada técnico, não uma inferência de vigilância ou a soma atribuída ao responsável atual.

## Aplicativo Windows

1. Na página Suporte, clique em **Baixar Arcoll Suporte para Windows**.
2. Extraia o pacote. Instale Python 3.10 ou superior pelo site oficial, se ainda não existir. Abra `Instalar_Suporte.cmd`.
3. O instalador cria um ambiente Python isolado em `%LOCALAPPDATA%\ArcollSuporte`, instala Pillow via PyPI com verificação TLS e cria um atalho. Não altera a instalação do agente, não exige privilégios administrativos e não reduz políticas de segurança.
4. Selecione um computador online na plataforma e clique em **Conectar pelo aplicativo Windows**. Informe a URL HTTPS e o código no aplicativo em até dois minutos.
5. O código pode ser usado uma vez. O token resultante fica somente em memória, vale para aquela sessão por até 30 minutos e é revogado ao encerrar. Remover o técnico da rede ou pausar sua conta interrompe a autorização.
6. O computador remoto mantém o aviso e a autorização local. Acesso não assistido exige configuração explícita de servidor e perfil administrativo da rede. O aplicativo suporta monitores, qualidade econômica/padrão/alta, mouse, teclado, envio de texto e tela inteira. Não contorna telas seguras do Windows/UAC.
7. Quadros inalterados usam ETag; o cliente não acumula imagens atrasadas nem solicita quadros concorrentes. O agente ajusta resolução e compressão reais conforme o perfil. A conexão do visualizador expira se perder o contato; o usuário remoto pode revogar o controle.

O pacote é um instalador em Python, **não um EXE compilado e assinado**. Construção da janela e protocolo foram testados em Linux/Tkinter; instalação, desempenho e controle físico precisam ser testados em Windows real. Não há promessa de desempenho equivalente a TeamViewer. O transporte atual permanece HTTPS com relay na hospedagem; latência, CPU e limites do cPanel continuam relevantes. P2P/WebRTC, relay dedicado, captura da área segura e distribuição de binário assinado não fazem parte desta implementação.

## Planos e cobrança

- O catálogo inicial sugere Essencial R$59,90 (3 redes / 15 agentes / 2 técnicos adicionais), Profissional R$149,90 (15 / 75 / 5) e Empresa R$399,90 (50 / 250 / 15). São sugestões editáveis, não uma análise de margem do negócio. Limite zero significa ilimitado. O proprietário não conta como técnico adicional.
- A página **Planos e cobrança** fica dentro da área autenticada. Não foi criada uma landing page pública.
- **Gestão comercial**, exclusiva do administrador, permite editar planos, configurar gateways, ver cobranças/assinaturas, conferir Pix e pausar ou retomar um período pago válido. Preços de faturas já emitidas são preservados.
- As contas existentes ficam preservadas sem cobrança forçada. A opção administrativa de avaliações estabelece uma data de início: apenas contas criadas a partir dela recebem a avaliação de 14 dias, com 1 rede, 3 agentes e 1 técnico adicional. Uma vez estabelecida, a data não é redefinida por edições posteriores.
- Após pagamento confirmado, o plano libera um período de 30 dias. Os limites são validados no servidor ao criar redes, aprovar agentes e vincular técnicos; as gravações usam bloqueio da conta para evitar ultrapassagens concorrentes. Uma redução de plano não apaga recursos existentes.
- Assinatura vencida, pausada ou em revisão bloqueia novas operações de suporte e expansão. Dados, histórico e consulta permanecem preservados. Contestações/estornos exigem revisão administrativa.

### Mercado Pago

Informe Access Token, ambiente teste/produção e a assinatura secreta de webhook do painel Mercado Pago. Configure notificações de pagamentos para:

`https://SEU-DOMINIO/api/billing-webhook.php?provider=mercadopago`

A integração cria uma preferência de checkout. O webhook valida HMAC/timestamp e consulta o pagamento na API oficial antes de validar referência, moeda e valor. O retorno do navegador nunca confirma pagamento.

### Asaas

Informe API key, ambiente teste/produção e o token de autenticação do webhook (o mesmo valor no Asaas, mínimo 16 caracteres). Configure notificações de pagamentos para:

`https://SEU-DOMINIO/api/billing-webhook.php?provider=asaas`

O primeiro checkout solicita CPF/CNPJ para criar o cliente no Asaas; o aplicativo não armazena esse documento. O webhook valida `asaas-access-token` e consulta a cobrança no provedor. As chaves ficam criptografadas com AES-GCM e a chave da instalação; preserve `app_secret` no backup.

### Pix manual

Configure chave, beneficiário e instruções. O cliente informa a referência da transação e o nome do pagador. **Esse envio não ativa o plano**: o administrador deve conferir a entrada no banco e confirmar na plataforma. Não há integração bancária automática ou validação de comprovante por imagem.

### Renovação e notificações

Agende uma vez ao dia no Cron do cPanel, adaptando o caminho do PHP fornecido pela hospedagem:

```sh
php /home4/arcoll06/app.digital.log.br/maintenance/billing-renewals.php
```

O comando é exclusivo de CLI, usa trava no banco, cria a próxima cobrança até sete dias antes do vencimento, prepara checkout nos gateways configurados, registra aviso na conta e envia e-mail se SMTP estiver habilitado. Eventos e faturas são idempotentes. A renovação é **por nova fatura**, não débito recorrente automático em cartão. O cliente paga a nova cobrança; não armazenamos cartões nem alegamos autorização para debitar automaticamente. Cancelar a renovação preserva o período já pago.

Em timeout ou resposta ambígua de um gateway, a cobrança fica em processamento para impedir repetição de uma operação externa incerta. Use a consulta/conciliação administrativa e verifique o painel do provedor; não crie pagamentos paralelos para forçar liberação. Reembolsos financeiros são feitos no gateway, não pelo botão de conciliação.

## Validações e limites

- Matriz de interface: 176 combinações de páginas, temas e dimensões; sem erros de JavaScript ou transbordamento horizontal nos casos exercitados.
- Painel: 35 cenários responsivos, paginação, busca, modais e navegação.
- Integração de serviços: 34 verificações de isolamento, papéis, chamados, registros de trabalho, tokens, idempotência financeira, limites, vencimento, estorno e assinatura de webhook.
- HTTP: 21 fluxos incluindo troca do código, despacho com identidade do técnico, escopo do token, JPEG, ETag, fila de controle, revogação local, download e administração comercial.
- Python: 19 testes, incluindo construção real das janelas Tkinter e verificações do novo cliente; não equivalem a testes em Windows físico.
- 63 arquivos PHP, JS, CSS e Python passam nas verificações de sintaxe. A migração foi reaplicada duas vezes sem alterar as contagens das 43 tabelas da base sintética. A renovação foi executada duas vezes na base sintética, sem duplicar fatura ou aviso.
- Pagamentos reais/sandbox nos provedores, entrega SMTP real, login Google externo, instalação Windows física e Android/iPhone físicos não foram executados. Configure as credenciais e valide uma compra em ambiente de teste de cada gateway antes de ativar produção.

A estrutura é multiempresa por conta, com escopo verificado nas novas consultas, índices nas filas e faturamento, paginação e migração aditiva. Não é uma certificação de capacidade ilimitada de hospedagem. Crescimento de sessões remotas requer medir concorrência do PHP, I/O de quadros, banco e banda, e planejar relay dedicado antes de vender capacidade que a infraestrutura não suporta.

## Desfazer

Restaure os arquivos e agentes do backup anterior durante manutenção. A migração nova apenas cria tabelas; não remova essas tabelas após registrar chamados, assinaturas ou cobranças. Para voltar sem perder esses registros, preserve o banco completo e as chaves de criptografia e planeje a compatibilidade da versão restaurada. Nunca restaure um banco antigo sobre pagamentos recentes sem conciliação financeira.

## Formatos sem comentários

`VERSION` é o caminho completo relativo à raiz do arquivo de versão em texto simples. Os marcadores de exclusão têm zero bytes e, portanto, não contêm comentários.
