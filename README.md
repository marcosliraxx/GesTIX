# Atualização atual · 5.0.389

[ZIP incremental: carregamento e temas](pacotes/CARREGAMENTO_TEMAS_5.0.389_EXTRAIR_NA_RAIZ.zip). O círculo acompanha o tema administrativo e aguarda a coleta real; na Internet ele inicia o teste. Extraia na raiz do cPanel e atualize o gestor Windows. [Detalhes e validação](documentacao/CARREGAMENTO_5.0.389.md).

---

# Correção atual · 5.0.388

[ZIP incremental: botão Corrigir seleção](pacotes/CORRECAO_BOTAO_PENTE_FINO_5.0.388_CPANEL.zip). Corrige a seleção acima de 20.000 ajustes. Extraia na raiz do cPanel, atualize o gestor e faça nova análise. [Detalhes](documentacao/CORRECAO_PENTE_FINO_5.0.388.md).

---

# T.I. Digital · base atual 5.0.387

A base de trabalho atual é o backup 5.0.386 anexado nesta solicitação, com a evolução do [Pente-Fino 5.0.387](documentacao/PENTE_FINO_5.0.387.md).

O ZIP incremental desta entrega está em [pacotes/PENTE_FINO_5.0.387_EXTRAIR_NA_RAIZ.zip](pacotes/PENTE_FINO_5.0.387_EXTRAIR_NA_RAIZ.zip). Ele contém apenas os arquivos alterados, com os caminhos prontos para extrair na raiz do cPanel. Depois, atualize o gestor Windows.

Os documentos e pacotes 5.0.152 abaixo são históricos da entrega anterior.

---

# T.I. Digital · Gestores 5.0.152

Esta é a nova base de trabalho: backup enviado em 08/11/2026 às 04:40, versão 5.0.151, com os ajustes da versão 5.0.152. O código antigo foi substituído na árvore atual; o histórico do Git foi preservado.

## Entrega

- Instalador Windows em janela WPF, progresso circular por etapas, animação, diagnóstico recolhível e cores administrativas. A conclusão depende do resultado real do processo de instalação.
- Iniciador VBS preservado e alternativa BAT disponível no diálogo de instalação. Ambos chamam a mesma interface; não deixam terminal de acompanhamento aberto. O Windows pode solicitar a elevação normal para os serviços.
- Logs técnicos da aplicação centralizados em `logs/`, com recebimento HTTPS autenticado dos gestores e envio inicial do diagnóstico do instalador. A fila local permite reenviar após falhas de conexão, com separação por conta/rede/dispositivo.
- Dez temas preservados e refinados: Windows, Ocean, Crystal, Graphite, Neon, Emerald, Sapphire, Sandstone, Aurora e Slate. Plataforma, instalador e gestores usam o catálogo administrativo, com variações clara/escura e modo automático.
- [Pesquisa técnica DWService em PDF](documentacao/ESTUDO_DWSERVICE.pdf), [HTML](documentacao/ESTUDO_DWSERVICE.html) ou [Markdown](documentacao/ESTUDO_DWSERVICE.md), com fontes oficiais e comparação com o acesso remoto da sua base nova. O motor remoto não foi substituído nesta entrega.

## Atualizar no cPanel

1. Faça backup dos arquivos e do banco atuais. Extraia `pacotes/SISTEMA_5.0.152_CPANEL.zip` na raiz da aplicação, onde estão `VERSION`, `api/`, `admin/` e `gestores/`. O ZIP não tem uma pasta intermediária.
2. Preserve seu `includes/config.php`, banco, `includes/storage/`, `uploads/`, `logs/` e `bkp/`. O pacote não contém suas credenciais nem os dados dessas pastas; inclui apenas arquivos de proteção vazios quando aplicável. Não apague essas pastas antes de extrair.
3. Confirme que o PHP pode criar e gravar a pasta `logs/`. Use permissões do usuário da hospedagem; não é necessário liberar `777`. Esta entrega não exige migração de dados de negócio.
4. Em **Admin/Configurações**, escolha o tema e o modo. Atualize os gestores instalados para **5.0.152**. O gestor consulta a configuração visual periodicamente e conserva a última configuração válida quando offline.
5. Para uma instalação nova, use **Instalar gestor** ou **Alternativa BAT** na rede desejada. O pacote é validado por SHA-256 antes da extração. A lógica operacional de instalação, serviços e recuperação da base recebida foi preservada.

A árvore atual do Git já remove o código obsoleto. Extrair um ZIP no cPanel não exclui arquivos antigos existentes: diretórios antigos como `agentes/` só devem ser retirados após confirmar que nenhum agendamento/integração ainda os utiliza. Não apague dados de clientes como parte dessa limpeza.

Releases pré-compiladas anteriores à 5.0.152 não são reutilizadas pelo instalador novo. Se a hospedagem ainda não tiver uma release atual publicada, permanece o fluxo de compilação já existente na sua base. O pacote contém fontes; não anuncia um executável novo como assinado.

## Logs e manutenção

Estrutura principal:

```text
logs/platform/ ou logs/             erros técnicos do PHP e componentes
logs/audit/                        resumo técnico da auditoria (histórico do banco preservado)
logs/gestores/account-N/network-N/gestor-N/AAAA-MM-DD/
logs/instaladores/account-N/network-N/key-HASH/AAAA-MM-DD/
logs/legacy/                       diagnósticos antigos importados pela ferramenta opcional
```

Os nomes exatos dos arquivos incluem componente/data ou identificador do lote. Os diretórios de logs são protegidos contra acesso HTTP. Credenciais conhecidas são ocultadas antes do envio e novamente no recebimento; ainda assim, diagnósticos devem ter acesso administrativo restrito. Não são coletados documentos, imagens, conteúdo do backup ou histórico de tela.

O envio dos gestores roda em ciclos de aproximadamente 30 segundos. Sem internet, os registros permanecem locais e a fila redigida tem limite de 32 MiB. O servidor aceita lotes de até 100 eventos/256 KiB, até 30 requisições por minuto e 20 MiB por dia por identidade. Um lote só é descartado pelo cliente depois da confirmação correspondente; reenviar o mesmo lote não duplica o registro. A fila de outra rede nunca é enviada com credenciais novas.

Instalações que falhem antes de o gestor iniciar tentam enviar o diagnóstico usando a chave temporária da instalação. Se não houver conexão, os arquivos locais permanecem em `%LOCALAPPDATA%\TI-Digital\logs`; um gestor instalado e autenticado posteriormente pode coletá-los. Não é possível transmitir diagnósticos de uma máquina que permaneça offline.

Para centralizar logs técnicos antigos já existentes nas pastas do servidor, execute uma vez pelo Terminal do cPanel:

```sh
php tools/centralizar-logs.php
```

A ferramenta só remove o arquivo técnico original depois de confirmar a gravação central; ignora arquivos de negócio e não segue links. Para a retenção de 30 dias, configure uma tarefa diária no Cron do cPanel, usando os caminhos reais da sua hospedagem:

```sh
php /CAMINHO/DA/APLICACAO/maintenance/logs-retention.php
```

O limite é de mil arquivos removidos por execução. A retenção não é ativada silenciosamente sem o agendamento. O logger também prepara `.user.ini` para erros PHP anteriores ao bootstrap em PHP-FPM/CGI compatível, preservando outras diretivas; o cache dessa configuração pode levar alguns minutos. Logs do Apache, MySQL e do sistema operacional pertencem à hospedagem e não são redirecionados por código PHP.

## Validação

A entrega tem testes isolados de autenticação da API, isolamento entre redes, reenvio offline, armazenamento atômico, redação de credenciais, catálogo, contraste e prioridade do tema administrativo. A interface WPF é validada e renderizada no Windows; WinUI e BootWorker são compilados no Windows. Consulte o [workflow](.github/workflows/validar-gestor-atual.yml) e [registro de validação](documentacao/VALIDACAO_5.0.152.md).

A [imagem do instalador](documentacao/PREVIA_INSTALADOR.png) é uma renderização da interface WPF real em modo de prévia, com dados de exemplo. A instalação completa, UAC, serviços e troca de versão devem ser conferidos na primeira máquina Windows de homologação antes da distribuição ampla.
