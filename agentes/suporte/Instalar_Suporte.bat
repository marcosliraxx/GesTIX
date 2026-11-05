@rem Caminho: agentes/suporte/Instalar_Suporte.bat
@echo off
cd /d "%~dp0"
py -3 -c "import sys;assert sys.version_info >= (3,10)" >nul 2>nul
if %errorlevel% equ 0 goto launcher
python -c "import sys;assert sys.version_info >= (3,10)" >nul 2>nul
if %errorlevel% equ 0 goto python
where winget >nul 2>nul
if errorlevel 1 goto missing
winget install --id Python.Python.3.12 --exact --scope user --accept-package-agreements --accept-source-agreements
if errorlevel 1 goto missing
if exist "%LocalAppData%\Programs\Python\Python312\python.exe" (
 "%LocalAppData%\Programs\Python\Python312\python.exe" Instalar_Suporte.py
 exit /b
)
:launcher
py -3 Instalar_Suporte.py
exit /b
:python
python Instalar_Suporte.py
exit /b
:missing
echo Instale Python 3.10 ou superior em https://www.python.org/downloads/windows/ e abra este arquivo novamente.
pause
