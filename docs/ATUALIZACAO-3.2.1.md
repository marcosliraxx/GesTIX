<!-- Caminho: docs/ATUALIZACAO-3.2.1.md -->
# Arcoll 3.2.1 — correção do checkout e histórico

Pacote consolidado com todas as alterações desde 3.1.0. Pode aplicar sobre 3.1.0 ou 3.2.0. Plataforma, agente e suporte Windows passam juntos para 3.2.1. Não atualiza o cPanel automaticamente.

## Como instalar

1. Faça backup dos arquivos e do banco.
2. Extraia o ZIP e envie **todos os arquivos não vazios**, mantendo as pastas, para a raiz do sistema. Não envie somente a pasta `pages`. É indispensável substituir `includes/billing.php` e `includes/bootstrap.php`, além das páginas e demais arquivos do pacote. Preserve sua configuração e os uploads.
3. Se a hospedagem mantiver código PHP antigo em cache, peça a ela para atualizar/reiniciar o cache PHP/OPcache depois de substituir os arquivos. Recarregar a página do celular não limpa o cache PHP do servidor. A atualização não tenta alterar configurações globais da hospedagem.
4. Reabra o sistema e confira **Cobranças** e **Pagamento**. O histórico deve mostrar as situações, a lista ou a mensagem de lista vazia, além da navegação inferior. O checkout Pix deve mostrar QR Code e copia e cola quando os dados de pagamento estiverem configurados. A referência de pagamento continua exigindo conferência administrativa.

Não há nova migração nesta correção. A migração aditiva 3.2.0 vem incluída para instalações ainda na 3.1.0 e é aplicada pelo fluxo existente. Ela não apaga registros. Não execute queries avulsas para tentar resolver as telas interrompidas.

Os arquivos com **zero bytes** são marcadores para exclusão manual dos arquivos antigos listados ao final. **Não envie os marcadores vazios para sobrescrever os arquivos no servidor.** Não publique este LEIA-ME na pasta pública do site.

`VERSION`, na raiz, não permite comentário de caminho. Os demais arquivos de código alterados identificam seu caminho no topo.

## Causa investigada e correção

Foi possível reproduzir as duas telas enviadas pelo usuário carregando as páginas 3.2.0 com o componente `includes/billing.php` da versão 3.1.0: o histórico interrompe a geração no primeiro item de situação; o checkout captura a ausência da função Pix, exibe o erro genérico e interrompe novamente ao tentar mostrar a situação. O rodapé não chega a ser gerado. Isso é compatível com atualização parcial ou código PHP antigo em cache, mas não confirma quais arquivos estavam carregados no servidor do usuário, ao qual não houve acesso.

A implementação original foi ajustada para:

- Manter o componente de cobrança explicitamente versionado e alinhado ao restante da release; o comando de atualização de versão e o teste de release incluem esse componente.
- Verificar as dependências antes de consultar/exibir cobranças ou iniciar pagamentos. Arquivos incompatíveis geram aviso de atualização incompleta, preservando a estrutura e navegação das páginas, sem criar pagamentos.
- Capturar falhas de consulta no histórico, evitando saída HTML interrompida. Falhas do checkout não renderizam um formulário parcialmente preparado; a consulta de situação retorna erro JSON válido quando necessário.
- Gerar a normalização do nome/cidade Pix sem depender da extensão opcional `iconv`, preservando a conversão de acentos portugueses e o formato BR Code.

Não foram criadas funções substitutas para esconder componentes antigos, arquivos de sobreposição ou scripts de correção paralelos. O pacote entrega os componentes originais atualizados.

## Verificações

- Reprodução das duas falhas com o componente de cobrança 3.1.0 na cópia isolada.
- 7 cenários com arquivos misturados: aviso claro, resposta de serviço indisponível, rodapé preservado e consulta de situação em JSON, incluindo celular retrato e paisagem.
- 6 cenários com arquivos alinhados: situações/listagem e navegação completas nos mesmos tamanhos de tela.
- 45 verificações do checkout, leitura do QR Pix, histórico, relatórios e permissões remotas passaram na release alinhada.
- 31 testes Python, incluindo alinhamento da nova versão de cobrança, plataforma e Windows.
- PHP sem erros de sintaxe; geração do nome/cidade Pix validada com `iconv` desativada; integridade do ZIP e marcadores conferida.

Não houve acesso ao cPanel, banco ou cache PHP do usuário. Pagamentos bancários reais e instalação física no Windows continuam sem validação neste ambiente.

## Desfazer

Restaure os arquivos do backup e mantenha os componentes de uma mesma versão. Não é necessário remover a coluna aditiva do Pix. Preserve cobranças e pagamentos novos; não restaure um banco antigo sobre transações recentes sem conciliação.
