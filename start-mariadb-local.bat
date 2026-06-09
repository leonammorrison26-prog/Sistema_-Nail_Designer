@echo off
setlocal enabledelayedexpansion

:: Define os caminhos relativos a pasta do projeto.
set "MARIADB_PATH=%~dp0.tools\mariadb\mariadb-11.4.8-winx64"
set "DATA_DIR=%~dp0data"
set "INI=%DATA_DIR%\my.ini"
set "MYSQLD=%MARIADB_PATH%\bin\mariadbd.exe"

if not exist "%MYSQLD%" (
    set "MYSQLD=%MARIADB_PATH%\bin\mysqld.exe"
    if not exist "!MYSQLD!" (
        echo ERRO: MariaDB nao encontrado em !MYSQLD!
        pause
        exit /b 1
    )
)

if not exist "%DATA_DIR%" mkdir "%DATA_DIR%"

:: Ajusta caminhos para o formato do my.ini (barras normais)
set "BASE_DIR_DB=%MARIADB_PATH:\=/%"
set "DATA_DIR_DB=%DATA_DIR:\=/%"

(
echo [mysqld]
echo basedir="%BASE_DIR_DB%"
echo datadir="%DATA_DIR_DB%"
echo innodb_data_home_dir="%DATA_DIR_DB%"
echo port=3306
echo bind-address=127.0.0.1
echo character-set-server=utf8mb4
echo collation-server=utf8mb4_unicode_ci
echo log-error="%DATA_DIR_DB%/mariadb-local.err"
echo skip-name-resolve
echo.
echo [client]
echo host=127.0.0.1
echo port=3306
echo default-character-set=utf8mb4
) > "%INI%"

:: Verifica se a porta 3306 ja esta em uso
netstat -ano | findstr LISTENING | findstr :3306 >nul
if %ERRORLEVEL% == 0 (
    echo ERRO: A porta 3306 ja esta sendo usada. Feche o MySQL/XAMPP/WAMP que ja esta rodando.
    pause
    exit /b 1
)

:: Inicializa o banco se a pasta 'mysql' nao existir
if not exist "%DATA_DIR%\mysql" (
    echo Inicializando tabelas do sistema MariaDB...
    "%MARIADB_PATH%\bin\mariadb-install-db.exe" --datadir="%DATA_DIR%"
)

echo Iniciando MariaDB em modo standalone...
echo MANTENHA ESTA JANELA ABERTA. O MariaDB sera iniciado aqui.
echo Se esta janela fechar imediatamente ou voce vir um erro,
echo verifique o arquivo de log: "%DATA_DIR%\mariadb-local.err" para mais detalhes.
echo.

:: Inicia o MariaDB em uma nova janela e espera que ela seja fechada.
start "MariaDB Local" /wait "%MYSQLD%" --defaults-file="%INI%" --standalone --console

echo.
echo MariaDB foi encerrado. Pressione qualquer tecla para fechar esta janela.
pause >nul