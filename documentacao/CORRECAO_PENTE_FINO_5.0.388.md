# Correção do botão — Pente-Fino 5.0.388

Corrige o bloqueio observado no resultado de 20.455 ajustes selecionados. A interface e o host não limitam mais a seleção total a 20.000. O motor aceita todos os IDs elegíveis presentes no relatório atual e continua validando origem, duplicidade, permissão, confirmação, backup e integridade de cada alvo.

A correção permanece restrita aos itens corrigíveis das áreas selecionadas. Registros informativos e revisões manuais não viram correções automáticas. O limite de coleta de uma área não é confundido com o total selecionado entre áreas.

Extraia `pacotes/CORRECAO_BOTAO_PENTE_FINO_5.0.388_CPANEL.zip` na raiz do cPanel, substituindo os arquivos. Atualize o gestor para 5.0.388 e execute uma nova análise. O ZIP contém apenas cinco arquivos alterados em relação à entrega 5.0.387.

Testes: 26 verificações .NET, incluindo a seleção exata de 20.455 ajustes e sua aceitação no motor antes de cancelamento controlado, sem alterar milhares de arquivos; 39 verificações no Chromium, incluindo o botão habilitado com esses totais e envio das quatro áreas selecionadas. O workflow também compila o gestor x64 no Windows.
