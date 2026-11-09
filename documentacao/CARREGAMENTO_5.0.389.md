# Carregamento das páginas — 5.0.389

Base: `bkp_app.digital.log.br_20261109_123552_199642.zip`, versão 5.0.388. Os arquivos enviados preservam as demais funcionalidades dessa base.

O carregamento anterior fechava após duas esperas fixas, antes de saber se a página tinha dados. O novo controle segue os estados reais de cada fonte: aguardando coleta, consultando, dados disponíveis ou falha. A conclusão da navegação deixa de ser confundida com conclusão da coleta.

## Internet

Na primeira abertura, enquanto não existe uma medição, o gestor apresenta o círculo no lugar dos resultados vazios. Clicar nele executa a mesma medição real do antigo Iniciar teste: latência, download, upload, perdas e diagnóstico. Percentuais e etapas vêm da operação existente. O resultado aparece após a conclusão; falhas mantêm a tela de tentativa, com explicação e possibilidade de repetir. Uma resposta sem nenhuma medição válida não vira um diagnóstico concluído.

O usuário pode interromper o teste. A troca de página não entrega os resultados atrasados de uma área para a tela de outra.

## Padrão das demais consultas

- Consultas automáticas ficam atrás do carregamento até retornarem os dados: inventário, mapa, arquivos, chamados, catálogo e monitor de componentes.
- Ferramentas de análise aguardam o clique no círculo. A ação de coleta é registrada explicitamente no código; o sistema não escolhe um botão pelo nome nem aciona limpeza, instalação ou exclusão automaticamente.
- As ferramentas com parâmetros permitem escolher opções antes de coletar. A análise volta a mostrar o carregamento quando começa. A interrupção continua acessível durante as coletas que oferecem cancelamento.
- Um resultado vazio válido, como uma consulta concluída sem chamados ou sem planos, não fica preso em carregamento infinito. Uma falha de consulta não é apresentada como resultado vazio bem-sucedido.
- Atualizações automáticas em segundo plano preservam dados já disponíveis. Pente-Fino e Atualização mantêm suas telas próprias de início e operação; o carregamento externo aguarda a inicialização da interface própria.
- As novas tentativas reaproveitam a bridge existente, evitando duplicar os manipuladores de comandos.

## Cores e progresso

Fundo, texto principal, legenda, trilha, círculo e animação têm cores próprias, recebidas do tema administrativo. O fundo não usa mais uma cor fixa translúcida. Há verificação de contraste para manter textos e anel legíveis inclusive quando uma configuração de cores é monocromática.

Nenhum temporizador cria porcentagem ou libera a página. Quando a fonte informa percentual, ele é exibido; quando não informa, o círculo mostra animação de espera com a etapa real, sem inventar progresso.

## Aplicação

Extraia `pacotes/CARREGAMENTO_TEMAS_5.0.389_EXTRAIR_NA_RAIZ.zip` na raiz do sistema no cPanel, substituindo os arquivos. Depois atualize o gestor Windows para **5.0.389**. A marca de versão mínima impede reutilizar um binário anterior sem a nova interface de carregamento. O ZIP não contém banco, credenciais, arquivos dos clientes, logs reais nem configurações locais.

## Validação

119 verificações .NET cobrem o ciclo de vida da coleta, nova tentativa, resultado atrasado, resultado vazio, preservação de dados e contraste dos dez temas claros/escuros. Testes no Chromium exercitam as tentativas das interfaces reais de Backup e Painel e a regressão do Pente-Fino. O gestor é compilado em Windows x64 pelo workflow `validar-carregamento.yml`.

Esses testes não executam consultas em máquinas dos clientes nem substituem a homologação visual e funcional no primeiro gestor Windows atualizado.

[Validação final no GitHub Actions](https://github.com/marcosliraxx/GesTI/actions/runs/42651214848): compilação Windows sem erros ou avisos, 119 verificações de estado e contraste, 39 verificações da interface do Pente-Fino e seis verificações de nova tentativa/bridge nas interfaces de Backup e Painel.
