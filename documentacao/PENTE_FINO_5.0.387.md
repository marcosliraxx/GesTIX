# Pente-Fino 5.0.387

Base utilizada: backup `bkp_app.digital.log.br_20261108_113930_628662.zip`, versão 5.0.386. A entrega contém somente as diferenças para essa base.

## Comportamento

- Círculo SVG no padrão visual da central de atualização, com gradiente, órbitas, halo e etapas. Percentual da análise corresponde às áreas concluídas; o percentual da correção corresponde aos ajustes processados. O tempo decorrido não inventa progresso. O modo de movimento reduzido é respeitado.
- Cinco opções compactas de uma palavra abaixo do círculo: Limpeza, Privacidade, Inicialização, Registro e Atualizações. A escolha faz parte do comando de análise, evitando a corrida com uma mensagem separada de preferências.
- Resultado em duas colunas. À esquerda, uma linha por área com quantidades, corrigíveis, revisão manual e cobertura parcial. À direita, círculo para nova análise, seleção de áreas e ações. Arquivos individuais e caminhos completos deixam de ser enviados para a interface.
- Correção expandida pelo host a partir das áreas e do identificador do relatório atual; não aceita caminhos de correção do JavaScript. Confirmação nativa resumida por área. Quarentena, cópia verificada, auditoria e conflitos na restauração permanecem ativos.
- Janela interna de conclusão distingue aplicados, falhas e não processados. Cancelar a confirmação preserva o diagnóstico. Cancelar durante execução não é apresentado como desfazer alterações. Resultados parciais não são apresentados como verificação integral.
- Backups recentes e restauração acessíveis pela barra padrão, com confirmação. Atalhos de revisão levam às ferramentas existentes de inicialização, registro e atualização. A análise não transforma inventários ou registros informativos em falhas do Windows.
- Temas administrativos aplicados à página e barras de rolagem finas. Listas grandes deixam de ser desenhadas no WebView; o progresso da correção não reenvia dicionários crescentes com milhares de IDs.

## Instalação

Extraia `pacotes/PENTE_FINO_5.0.387_EXTRAIR_NA_RAIZ.zip` na raiz da aplicação no cPanel, substituindo os arquivos. Atualize o gestor Windows para 5.0.387 para receber a interface e o host juntos. A marca de versão mínima impede reaproveitar um binário anterior sem o novo contrato de resumo. Credenciais, SQL, dados, logs e preferências locais não fazem parte do ZIP.

Esta é uma implementação própria do Pente-Fino, com fluxo de análise, revisão e correção; não é uma cópia do produto Advanced SystemCare nem promete reproduzir todos os seus motores proprietários.

## Validação

- 24 verificações .NET com o código real: resumo e classificação, redução de 20.000 resultados para um payload pequeno, seleção por categoria, rejeição de relatório antigo/interrompido, quarentena e backup, restauração, cancelamento, proteção de arquivo alterado e contadores reais de sucesso/falha.
- 35 verificações no Chromium com a moldura real do gestor: comandos selecionados, confirmação cancelada sem perda de resultado, progresso por etapa, resumo sem lista de arquivos, revisão manual, modais, cancelamento, tema, janela compacta e movimento reduzido.
- Compilação WinUI x64 no Windows. [Execução final no GitHub Actions](https://github.com/marcosliraxx/GesTI/actions/runs/42494597831).

Os testes de correção utilizam apenas arquivos temporários controlados. Os testes de navegador simulam o transporte WebView2 e não executam alterações no computador do cliente. A navegação e os diálogos completos devem ser homologados no primeiro gestor Windows atualizado.
