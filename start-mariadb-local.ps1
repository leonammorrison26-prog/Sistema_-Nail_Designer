$ErrorActionPreference = "Stop"

# Define os caminhos relativos a pasta do projeto.
$mariadbPath = Join-Path $PSScriptRoot ".tools\mariadb\mariadb-11.4.8-winx64"
$dataDir = Join-Path $PSScriptRoot "data"
$mysqld = Join-Path $mariadbPath "bin\mariadbd.exe"
$ini = Join-Path $dataDir "my.ini"
$errorLogFile = Join-Path $dataDir "mariadb-local.err" # Define o caminho do log de erro

if (-not (Test-Path $mysqld)) {
    $mysqld = Join-Path $mariadbPath "bin\mysqld.exe"
    if (-not (Test-Path $mysqld)) {
        throw "MariaDB nao encontrado em $mysqld"
    }
}

if (-not (Test-Path $dataDir)) {
    Write-Host "Criando pasta de dados local em $dataDir..." -ForegroundColor Yellow
    New-Item -ItemType Directory -Path $dataDir -Force | Out-Null
}

# Recria o my.ini com os caminhos reais. Isso evita problemas com acentos e espacos.
$basedir = $mariadbPath.Replace("\", "/")
$datadir = $dataDir.Replace("\", "/")
$iniContent = @"
[mysqld]
basedir=$basedir
datadir=$datadir
innodb_data_home_dir=$datadir
port=3306
bind-address=127.0.0.1
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
log-error=$errorLogFile # Usa a variavel definida
skip-name-resolve # Evita que o MariaDB tente resolver nomes de host para conexoes, o que pode ser lento ou falhar

[client]
host=127.0.0.1
port=3306
default-character-set=utf8mb4
"@
Set-Content -LiteralPath $ini -Value $iniContent -Encoding ASCII

# Verifica se a porta 3306 ja esta em uso por outro processo
$portBusy = Get-NetTCPConnection -LocalPort 3306 -ErrorAction SilentlyContinue
if ($portBusy) {
    $processId = $portBusy[0].OwningProcess
    $processName = (Get-Process -Id $processId).ProcessName
    Write-Host "ERRO: A porta 3306 ja esta sendo usada pelo processo: $processName (PID: $processId)" -ForegroundColor Red
    Write-Host "Feche o MySQL/XAMPP que ja esta rodando antes de iniciar este script." -ForegroundColor Yellow
    # Adiciona uma pausa para o usuario ler a mensagem de erro
    pause
    exit
}

# Verifica se o processo do MariaDB ja esta rodando
$runningMariaDB = Get-Process -Name "mariadbd", "mysqld" -ErrorAction SilentlyContinue
if ($runningMariaDB) {
    Write-Host "MariaDB ja esta rodando (PID: $($runningMariaDB.Id)). Nao sera iniciado novamente." -ForegroundColor Green
    pause
    exit
}

# Desbloqueia recursivamente a pasta do MariaDB para evitar "Acesso Negado".
Write-Host "Verificando permissoes dos arquivos do MariaDB..." -ForegroundColor Yellow
Get-ChildItem -Path $mariadbPath -Include *.exe,*.dll -Recurse | Unblock-File -ErrorAction SilentlyContinue
Get-ChildItem -Path $dataDir -Recurse | Unblock-File -ErrorAction SilentlyContinue

# Se a pasta 'mysql' não existe dentro de 'data', o banco precisa ser inicializado
if (-not (Test-Path (Join-Path $dataDir "mysql"))) {
    Write-Host "Inicializando tabelas do sistema MariaDB (mariadb-install-db.exe)..." -ForegroundColor Yellow
    $installDb = Join-Path $mariadbPath "bin\mariadb-install-db.exe"
    try {
        # Adiciona --auth-root-authentication-method=normal para garantir que o root nao tenha problemas de autenticacao inicial
        & $installDb --defaults-file="$ini" --datadir="$datadir" --basedir="$basedir" --auth-root-authentication-method=normal
        Write-Host "Inicializacao do banco de dados concluida." -ForegroundColor Green
    } catch {
        Write-Error "Falha ao inicializar o banco de dados: $($_.Exception.Message). Verifique o log de erro em '$errorLogFile'."
        pause
        exit
    }
}

Write-Host "Tentando iniciar o MariaDB..." -ForegroundColor Cyan
Write-Host "Iniciando MariaDB em modo standalone (--console)..." -ForegroundColor Gray
Write-Host "Mantenha esta janela do PowerShell aberta para o MariaDB continuar funcionando." -ForegroundColor Green
# O operador '&' executa o comando e mantém o terminal ocupado.
& $mysqld --defaults-file="$ini" --standalone --console

Get-Process mysqld,mariadbd -ErrorAction SilentlyContinue | Select-Object Id,ProcessName,Path
