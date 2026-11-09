# Pente-Fino integrado e carregamento do gestor

Base: backup `bkp_app.digital.log.br_20261109_133658_829330.zip` enviado pelo usuário. O controle automático de Admin/Versão foi preservado; este pacote não altera VERSION nem MINIMUM_RELEASE_VERSION.

## Aplicação

Extraia `pacotes/PENTE_FINO_CENTRAL_EXTRAIR_NA_RAIZ.zip` na raiz do sistema no cPanel, sobrescrevendo os arquivos correspondentes. Os caminhos já estão montados. Aguarde o controle administrativo reconhecer a alteração e atualize o gestor instalado pelo ícone de atualização. Não é necessário substituir configurações, banco de dados ou arquivos dos clientes.

## Comportamento

- O Pente-Fino reúne Análise geral e treze ferramentas em um menu lateral interno: Limpeza, Registro, Inicialização, Desempenho, Restauração, Segurança, Desinstalar, Atualizações, Drivers, Windows Update, Discos, Duplicados e Sincronizados.
- Os mesmos itens deixam de aparecer duplicados nas categorias principais e nos favoritos. Links antigos continuam abrindo a ferramenta correspondente dentro do Pente-Fino. Favoritos e organização das demais páginas são preservados.
- As ferramentas existentes são reutilizadas como componentes internos, preservando seletores, validações e operações especializadas. Os arquivos internos não foram apagados: excluir dependências em uso interromperia essas funções. Não há uma segunda cópia de cada motor.
- A análise geral continua resumindo suas cinco áreas por quantidade. As ferramentas técnicas da lateral têm suas próprias consultas e ações; instalar drivers, desinstalar programas e aplicar atualizações exige seleção e confirmação específica. Inventário não é classificado automaticamente como falha.
- A coluna do círculo ocupa mais espaço. Animações, halos, órbitas e contraste seguem o tema administrativo e respeitam a preferência de movimento reduzido.
- A coleta global ganha anel maior, gradiente, marcações e satélite animado. A porcentagem continua vindo dos dados reais; animação não simula avanço.
- As ferramentas integradas mantêm a barra comum, Atividades recolhíveis, carregamento antes dos dados e informações no rodapé do gestor.

## Correções e recuperação

A confirmação da análise geral agora pergunta **Confirma ajustes e correções?**, oferece **Criar ponto de restauração no Windows** e os botões **Corrigir** e **Cancelar**. A caixa inicia desmarcada. Se for marcada, o Windows deve confirmar a criação do ponto antes das alterações; é necessário executar o gestor como administrador e ter proteção do sistema disponível. Falhas interrompem essa correção antes das mutações.

Por solicitação expressa do usuário, novas correções não criam quarentena, arquivos .reg/JSON de recuperação ou cópias dos bancos de cookies. Mantêm validação de alvo, seleção, atualidade do relatório, auditoria, cancelamento e proteção contra alterações posteriores à análise. O registro e a inicialização avançados também recebem explicitamente o modo sem cópias. As chamadas existentes da plataforma mantêm seu comportamento anterior quando não usam esse modo.

Cópias de sessões antigas não são apagadas por esta migração. A recuperação legada só aparece na análise geral quando há sessões recuperáveis. O produto Backup local/nuvem é independente e não foi alterado.

## Validação

- 27 verificações .NET: resumo, seleção de 20.455 ajustes, exclusão sem cópia, auditoria, cancelamento e preservação de arquivos alterados.
- 4 testes Python: registro e inicialização sem exportação/cópia, preservando alvos não selecionados.
- 39 verificações no Chromium: seleção, progresso real, temas, integração com a moldura, diálogos e layout compacto.
- 6 verificações de coleta/repetição no Painel e Backup.
- Compilação WinUI x64 e 119 verificações de carregamento/contraste no fluxo Windows do GitHub Actions.

Os testes do Windows compilam o aplicativo; não substituem uma execução interativa nos computadores dos clientes. Não foram efetuadas correções em dados reais.
