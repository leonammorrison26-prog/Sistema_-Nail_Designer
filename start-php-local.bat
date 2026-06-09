@echo off

:: Variaveis de ambiente para o banco
set "DB_HOST=127.0.0.1"
set "DB_PORT=3306"
set "DB_NAME=salao_sammy"
set "DB_USER=root"
set "DB_PASS="

:: Caminho do PHP
set "PHP_EXE=%~dp0.tools\php\php.exe"

if not exist "%PHP_EXE%" (
    echo ERRO: Executavel do PHP nao encontrado em: %PHP_EXE%
    pause
    exit /b 1
)

echo Iniciando servidor PHP em http://localhost:8000...
"%PHP_EXE%" -S localhost:8000
pause