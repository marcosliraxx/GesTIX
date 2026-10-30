<!-- Caminho: docs/ATUALIZACAO-3.3.2.md -->
# Arcoll 3.3.2 — sistema organizado a partir do backup atual

Base analisada: `bkp_app.digital.log.br_20261030_130311_620368.zip`, fornecida pelo usuário. A entrega preserva as implementações atuais de mapas, suporte, Arquivos, cobrança, permissões e atualização do agente. Configuração, uploads e dados de produção não entram no pacote nem no GitHub.

## Instalação

1. Guarde o backup atual dos arquivos e do banco.
2. Extraia **Arcoll-3.3.2-atualizacao.zip** diretamente na raiz de `app.digital.log.br`, mantendo as pastas. **Sobrescreva todos os arquivos não vazios, inclusive quando o conteúdo parecer igual.** Parte desta correção é renovar a data dos arquivos para o PHP reconhecer a atualização; não pule esses arquivos apenas pelo tamanho.
3. Os arquivos vazios indicam **exclusão manual** dos caminhos listados ao final. Não envie esses marcadores para sobrescrever arquivos. Há 12 marcadores: as 11 implementações antigas já aposentadas e `_manifest.json`, metadado antigo de backup sem dependência no aplicativo. Não altere o manifesto dentro do seu ZIP de backup original; a exclusão se refere somente ao arquivo solto na raiz do site, caso exista.
4. Reabra o PWA e abra **Rede → Ferramentas**. O primeiro cartão deve ser **Arquivos**. No botão de ajuda (?), a página deve identificar **3.3.2**. A confirmação de atualização do agente mostra as versões do pacote no servidor.
5. Atualize o agente para **3.3.2** após conferir o pacote. O agente já instalado não foi atualizado por esta entrega; a atualização remota continua usando a autenticação existente.

**Não é necessária nova query SQL.** O backup já contém a coluna Pix e a tabela de transferências. Nenhum dado foi importado, apagado ou substituído. A versão do esquema permanece a mesma.

### Se o PHP continuar mostrando a tela antiga

O pacote corrige a data dos arquivos e foi validado com OPcache verificando alterações de data. Se a hospedagem desativou essa verificação ou mantém outra camada de cache PHP, será necessário reiniciar o PHP/PHP-FPM do domínio ou pedir à hospedagem uma limpeza do OPcache. Isso não é resolvido apenas limpando o PWA. A configuração real do OPcache do servidor não está presente no backup, por isso não foi alterada nem presumida.

Mensagem que pode ser enviada à hospedagem: “Os arquivos do domínio app.digital.log.br foram substituídos, mas o PHP continua executando conteúdo antigo. Favor reiniciar o PHP/PHP-FPM ou limpar o OPcache deste domínio e verificar a validação das datas dos arquivos.”

## Diagnóstico encontrado

- O domínio e a pasta de origem do backup correspondem à raiz conhecida do aplicativo.
- As páginas, APIs e fontes do agente auditadas no backup já correspondiam aos arquivos publicados em 3.3.1. O cartão Arquivos está presente em `redes/ferramentas.php`; não faltava essa implementação no arquivo do backup.
- Os ZIPs anteriores eram gravados com `ZipInfo` sem data explícita. Isso atribuía **01/01/1980** aos arquivos em todas as versões. O backup confirma essa data nos arquivos PHP, enquanto os logs têm datas normais de 2026.
- Substituir conteúdo e manter a mesma data pode deixar o OPcache usando o código compilado anterior. Reproduzimos exatamente isso em PHP 8.3.36: conteúdo novo no disco, resposta antiga no navegador; após extrair o ZIP corrigido, a resposta passou ao conteúdo novo.
- O log da API registra incompatibilidade de versões sendo exibida como erro genérico de banco. O log de páginas também registra execução de funções antigas que já tinham sido substituídas no código em disco. Esses registros, as datas e as diferenças entre a tela e o arquivo são compatíveis com código PHP antigo em cache. A configuração efetiva da hospedagem ainda precisa ser confirmada se o problema persistir.

## Organização e correções na origem

- O gerador original dos ZIPs agora usa a data real do empacotamento e inclui metadado UTC para extratores compatíveis, em vez da data fixa de 1980. Permissões de arquivo e caminhos são preservados.
- O pacote foi calculado contra o backup fornecido. Arquivos de código que já estavam atuais também foram incluídos com **data renovada**, pois são necessários para invalidar o cache antigo. A lista desses arquivos aparece ao final. Não foram criadas cópias paralelas, arquivos de sobreposição ou funções para contornar implementações antigas.
- Nenhum script de reset de cache foi adicionado ao aplicativo. A correção está no empacotamento; operações administrativas do PHP continuam com a hospedagem.
- Arquivos de código sem identificação completa receberam comentário de caminho no topo. Instaladores PowerShell tiveram finais de linha normalizados, sem mudar seus comandos; licenças dos componentes de terceiros foram preservadas.
- O antigo `_manifest.json` foi retirado do código após verificar a ausência de dependências. Ele era metadado de backup, não o manifesto do PWA. O PWA continua usando `manifest.webmanifest` e `api/manifest.php`.
- Ícones, fontes padrão e o iniciador CMD do suporte, que não aparecem no backup, foram incluídos a partir dos ativos existentes do projeto. A ausência no backup não foi tratada como prova de exclusão no servidor. Logos e avatares em uploads, assim como as preferências de marca do banco, permanecem preservados.
- Os logs de erro foram usados somente para diagnóstico e não foram incluídos na entrega. A proteção HTTP existente dos diretórios privados/logs foi mantida.

## Arquivos preservados fora da entrega

Não substituir `includes/config.php`, uploads, armazenamento privado, banco, logs nem o backup original. O arquivo `VERSION` não permite comentário; seu caminho é `VERSION`. Os ativos binários de imagens/fontes também não recebem comentários internos; seus caminhos estão na lista automática de arquivos novos/atualizados ao final.

## Validação

- Auditoria das versões e comparação dos arquivos do backup; análise da estrutura das 44 tabelas sem importar dados de produção.
- 4 verificações em servidor PHP/OPcache real, incluindo reprodução do cache antigo com data de 1980 e extração do ZIP corrigido pelo próprio PHP.
- 42 testes Python, incluindo novos testes de data/UTC/permissões do ZIP, proteção contra inclusão de configuração/logs/SQL de produção e os testes existentes de agente, arquivos e versão.
- 232 combinações de páginas, temas e tamanhos de tela sem falhas.
- 39 cenários de inventário populado, mapa ajustado pela largura/visão geral, planos, login e modais.
- 45 verificações de suporte compacto, criação/resolução de chamados, transferência binária nos dois sentidos, integridade, autenticação e isolamento de contas.
- 33 verificações da conferência do pacote, versões, cartão Arquivos e modais de atualização.
- Sintaxe PHP/JavaScript/Python, análise dos CSS, integridade do ZIP e exclusão de dados privados verificadas.

Não foram testados o OPcache efetivo da hospedagem, Windows físico, execução dos instaladores PowerShell em Windows, iPhone/Android físicos ou pagamentos bancários. Os instaladores receberam somente comentários/correção de formatação; os comandos foram preservados. Nenhuma imagem de apresentação foi criada.

## Desfazer

Restaure os arquivos do backup mantendo as versões dos componentes alinhadas. Se houver cache PHP persistente, também será necessário invalidá-lo para que a restauração tenha efeito. Preserve o banco e os arquivos privados; esta versão não exige reversão de esquema.
