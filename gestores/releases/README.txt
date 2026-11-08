# Caminho: gestores/releases/README.txt
Publicar release-build/win-x64/current e win-arm64/current gerados em Windows com PUBLICAR_RELEASE_PRECOMPILADA.ps1 (gestores/servidor/Arcoll.WinUI3).
Enviar o CONTEUDO da pasta (release.json + pasta files) para gestores/releases/<arch>/current neste servidor.
Nao expor binarios diretamente via URL (.htaccess bloqueia). A API autenticada fornece somente delta SHA256.

A release precompilada tem versao PROPRIA (a do release.json). A plataforma passa a considerar essa versao como
a versao-alvo dos gestores: ajustes de PHP/CSS no site nao tornam mais os gestores "desatualizados" nem invalidam a release.
Sem release publicada, a atualizacao cai no modo antigo: o computador baixa os fontes e COMPILA (lento, exige SDK .NET).
