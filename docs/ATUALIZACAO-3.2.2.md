<!-- Caminho: docs/ATUALIZACAO-3.2.2.md -->
# Arcoll 3.2.2 — contratação livre e histórico completo

Pacote consolidado com todas as alterações desde 3.1.0. Aplicável sobre 3.1.0, 3.2.0 ou 3.2.1. Plataforma, componente de cobrança, agente e suporte Windows ficam alinhados em 3.2.2.

## Instalação

1. Faça backup dos arquivos e do banco.
2. Extraia e envie **todos os arquivos não vazios**, mantendo as pastas. Substitua especialmente `pages/cobrancas.php`, `includes/billing.php` e `includes/bootstrap.php`. Não envie somente a pasta de páginas ou somente o componente de cobrança. Preserve configuração e uploads.
3. Se a hospedagem mantiver PHP antigo em cache, solicite a ela a atualização do cache PHP/OPcache após a substituição. As páginas autenticadas não são guardadas pelo service worker do sistema.
4. Confira a lista em Cobranças e o menu inferior no celular. Os filtros de situação e período agora ficam no botão de ajustes, ao lado da busca. Escolha outro plano mesmo com uma cobrança anterior pendente: ele deve abrir seu checkout normalmente.

Não existe nova migração nesta correção. A migração aditiva 3.2.0 permanece no pacote para instalações que ainda precisem dela, pelo fluxo automático existente. Não é necessário executar uma nova query manual.

Os 11 arquivos de zero bytes são marcadores para **exclusão manual** dos arquivos antigos listados ao final. Não envie os marcadores vazios para sobrescrever arquivos do servidor. Não publique estas instruções no diretório público. `VERSION` é o arquivo da raiz que não permite comentário de caminho.

## Alterações nativas

- Removida a regra que exigia concluir/cancelar qualquer cobrança pendente antes de escolher outro plano ou meio de pagamento.
- Cobranças de escolhas diferentes são preservadas e não são canceladas automaticamente. Repetir o mesmo plano e meio de pagamento reutiliza sua cobrança aberta, evitando duplicação por cliques repetidos. A chave de ciclo das cobranças novas diferencia o meio de pagamento; as cobranças anteriores continuam válidas.
- A conta continua com uma assinatura ativa; escolher um checkout não altera o plano. A ativação depende do pagamento confirmado, conforme a regra existente. Pix manual continua exigindo conferência administrativa.
- Refeito o controlador e a apresentação de `pages/cobrancas.php`: situações, valores, datas e dados das cobranças são preparados antes da emissão do HTML. Nenhuma função específica da cobrança é chamada dentro dos filtros ou cartões durante a montagem da página. Uma falha de preparação gera um aviso completo, mantendo a navegação.
- Busca com ações visíveis; situação e datas em modal compacto, com aplicação e limpeza dos filtros. O rodapé padrão é incluído tanto na listagem normal quanto na situação de erro. A resposta da página informa `X-Arcoll-Release`, permitindo à hospedagem conferir qual versão foi executada.

Não foram acrescentados arquivos de sobreposição, funções substitutas para manter implementações antigas ou correções paralelas. As mudanças estão nos componentes originais.

## Validação

- 45 verificações de serviços: incluem escolher outro plano com cobrança pendente, preservar a cobrança original, escolher outro meio com cobrança em conferência e reutilizar uma escolha repetida.
- 47 verificações HTTP/navegador: incluem abrir outro checkout sem cancelar o anterior, preservar a cobrança original, QR Pix legível, histórico, relatórios e permissões das ações remotas.
- 6 cenários do histórico/checkout com componentes alinhados e 7 com componente antigo: rodapé presente **e medido dentro da área visível** em telas de 320×568, 390×844 e 844×390. As falhas de dependência geram aviso e JSON válido, sem interromper a estrutura.
- 31 testes Python, incluindo alinhamento de versão dos componentes.
- PHP sem erros de sintaxe e verificação de integridade do ZIP.

Os testes foram executados em cópias isoladas com dados fictícios. Não houve acesso ao cPanel do usuário; não é possível confirmar quais arquivos/cache estão em execução naquele servidor. iPhone físico, pagamentos bancários e instalação física Windows não foram testados.

## Desfazer

Restaure os arquivos do backup mantendo uma mesma versão de todos os componentes. Preserve o banco e as cobranças geradas; esta correção não exige remover colunas. Não restaure um banco antigo sobre pagamentos recentes sem conciliação.
