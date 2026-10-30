@rem Caminho: support/Instalar_Suporte.cmd
@echo off
cd /d "%~dp0"
where py >nul 2>nul
if %errorlevel% equ 0 (
 py -3 Instalar_Suporte.py
) else (
 python Instalar_Suporte.py
)
if errorlevel 1 echo Instale o Python 3.10 ou superior pelo site https://www.python.org/downloads/windows/ e tente novamente.
pause
