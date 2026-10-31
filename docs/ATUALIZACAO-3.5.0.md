<!-- Caminho: docs/ATUALIZACAO-3.5.0.md -->
# Arcoll 3.5.0 — suporte nativo, códigos e Designer

Suporte mantém seis botões; Acesso remoto Windows foi removido, com o modal e o JavaScript sem dependências. Acesso web continua no fluxo original. O aplicativo baixado tem dois modos: código de cinco caracteres e login opcional com e-mail/senha da conta da plataforma. A tela de redes/computadores lista até 500 equipamentos autorizados; convidados de somente leitura não recebem acesso remoto. Ações são revalidadas no servidor, não apenas pela lista exibida.

## Acesso remoto

O ícone ↔ ao lado da engrenagem do agente abre o código centralizado. Código aleatório, cinco caracteres sem caracteres ambíguos, três minutos, uso único, armazenado somente como hash. Apenas agente autenticado pode gerar; até cinco gerações por minuto por agente. Tentativas públicas usam o limitador de autenticação existente por IP. Nova geração expira o código anterior não utilizado. Visitante sempre precisa de autorização local, inclusive em servidor marcado sem monitor; antes dessa autorização não há imagem nem controle. O código não é senha permanente. Após troca, o visualizador usa token aleatório de 256 bits, restrito à sessão e com duração máxima de 30 minutos.

Login nativo aceita a senha normal da conta de usuário, com as duas formas de hash já existentes. Não trata login de administrador global da plataforma como conta de rede; permissões de proprietário/administrador referem-se à equipe da rede. Não foi implementado OAuth Google dentro do aplicativo; contas que usam exclusivamente Google precisam configurar senha na plataforma ou usar acesso por código assistido. O token de conta expira em oito horas, é mantido apenas na memória e é revogado ao sair. Redes, permissões, situação da conta e autorização da assinatura são verificadas novamente ao conectar. Revogar a equipe, desativar o agente/rede ou retirar a permissão administrativa impede o acesso. Sessões originalmente não assistidas também são revogadas ao remover o modo sem monitor ou rebaixar o administrador.

Proprietário/administrador pode acessar automaticamente quando o agente está sem monitor. Outros técnicos continuam com confirmação local. O mesmo critério se aplica a arquivos e energia; energia e agendamentos registram o solicitante para permitir revogação de administradores delegados, em vez de atribuir implicitamente tudo ao proprietário. Dados e agendamentos anteriores continuam válidos pelo proprietário.

O aplicativo mantém mouse/teclado, rolagem, envio/colagem de texto, seleção de monitor, qualidade, tela inteira e encerramento e acrescenta os atalhos existentes no web, zoom/ajuste e rolagem da imagem ampliada. Ctrl+Alt+Del/área segura do Windows continuam sujeitos às limitações do sistema operacional. Transporte permanece HTTPS pelo servidor: não foi implementado relay/P2P, codec de vídeo ou driver como o AnyDesk; o desempenho depende da hospedagem, banda e imagens. Não há promessa de desempenho equivalente.

## Arquivos sem monitor

Somente proprietário/administrador autorizado pula a confirmação. Recebidos ficam em Downloads/ArcollRecebidos com nome único, sem sobrescrever arquivos. Para enviar automaticamente, coloque exatamente um arquivo em Documentos/ArcollCompartilhados. Não percorre outras pastas e não segue arquivos simbólicos. Sem arquivo ou com vários, retorna instrução para selecionar previamente um único arquivo compartilhado. Não executa os arquivos. Com monitor/sem permissão administrativa, os seletores e a confirmação existentes permanecem. Integridade, 8 MB, armazenamento privado e expiração de 24 horas continuam.

## Designer e instalação

Configurações administrativas > Designer oferece paletas azul, violeta, verde, laranja ou personalizada para agente e suporte, cor personalizada e ícones por upload PNG/JPG/WebP (até 1,5 MB/2048 pixels) ou URL HTTPS. Vazio usa a logo do sistema; sem logo personalizada, usa o ícone padrão da plataforma. Ícones Windows são gerados como ICO. Downloads incluem apenas aparência pública, sem credenciais de contas. Clientes buscam ícones com TLS verificado, sem encaminhar tokens e sem seguir redirecionamentos. O agente recebe alterações pelo fluxo original de configuração; suporte consulta metadados públicos ao abrir. O instalador usa o design incluído no download.

Baixe novamente o aplicativo em Suporte e extraia todo o ZIP. Abra Instalar_Suporte.bat. Instalação visual com progresso, ambiente Python isolado por usuário, dependências Pillow e atalho com ícone próprio; não altera o agente. Se Python não existir, tenta instalá-lo pelo winget oficial no escopo do usuário; se indisponível, orienta instalar Python 3.10+ manualmente. Não é EXE compilado/assinado e requer internet HTTPS para instalar dependências. Agente azul e suporte violeta por padrão. Aplicativos já instalados precisam receber esta versão; suporte anterior deve ser reinstalado com o novo pacote.

## Banco e entrega

Migração database/migrations/3.5.0.sql aditiva/reaplicável: cria quatro tabelas de códigos/tokens/solicitantes e acrescenta unattended à tabela original de sessões. O fluxo de esquema existente aplica automaticamente, com permissão para CREATE/ALTER; se necessário, execute somente essa migração no banco correto, adaptando o prefixo arc_. Não importe schema.sql em produção. Preserve backup antes de instalar.

ZIP incremental desde 3.4.0. Enviar todos os arquivos não vazios em suas pastas. Excluir manualmente support/Instalar_Suporte.cmd, substituído pelo BAT; os demais marcadores vazios são exclusões antigas já identificadas. Não sobrescrever com marcadores. VERSION não admite comentário: caminho VERSION na raiz. Plataforma/agente/suporte 3.5.0 alinhados. Para desfazer, encerrar sessões, cancelar novos agendamentos administrativos e restaurar arquivos 3.4.0; manter as tabelas/coluna novas e histórico, sem exclusão de dados. GitHub não instala no cPanel/Windows.

## Validação

44 testes Python/Tk com arquivos fictícios, incluindo transferência automática restrita às pastas gerenciadas; 26 verificações HTTP de login/código, consentimento, administrador/proprietário, isolamento, revogação e migração; 22 verificações de agendamentos; 267 verificações de suporte/chamados/arquivos/layout; 232 combinações de páginas/temas/telas; 39 cenários populados de inventário/mapa; 47 de cobrança/atualização/energia; 33 de preflight e 20 controles responsivos do Designer. ZIP do instalador gerado e validado com ícone ICO e metadados públicos. Sintaxe PHP/Python/JS/CSS verificada. Sem imagens/capturas. Não executados instalação winget/BAT/COM em Windows real, controle físico, desligamento real, PWA em aparelho ou cPanel. Devem ser testados no seu ambiente antes de disponibilizar aos clientes.
