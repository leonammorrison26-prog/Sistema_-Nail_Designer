$ErrorActionPreference = "Stop"

$mariadbPath = Join-Path $PSScriptRoot ".tools\mariadb\mariadb-11.4.8-winx64"
$dataDir = Join-Path $PSScriptRoot ".tools\mariadb-data"
$mysqld = Join-Path $mariadbPath "bin\mariadbd.exe"
$ini = Join-Path $dataDir "my.ini"

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
log-error=$datadir/mariadb-local.err

[client]
host=127.0.0.1
port=3306
default-character-set=utf8mb4
"@
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($ini, $iniContent, $utf8NoBom)

Write-Host "Verificando permissoes dos arquivos do MariaDB..." -ForegroundColor Yellow
Get-ChildItem -Path $mariadbPath -Include *.exe,*.dll -Recurse | Unblock-File -ErrorAction SilentlyContinue
Get-ChildItem -Path $dataDir -Recurse | Unblock-File -ErrorAction SilentlyContinue

$existing = Get-Process mysqld,mariadbd -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "MariaDB ja esta em execucao." -ForegroundColor Green
    $existing | Select-Object Id,ProcessName,Path
    exit 0
}

if (-not (Test-Path (Join-Path $dataDir "mysql"))) {
    Write-Host "Inicializando tabelas do sistema MariaDB..." -ForegroundColor Yellow
    $installDb = Join-Path $mariadbPath "bin\mariadb-install-db.exe"
    & $installDb "--defaults-file=$ini" "--datadir=$dataDir" "--basedir=$mariadbPath"
}

Write-Host "Iniciando MariaDB em segundo plano..." -ForegroundColor Cyan
$outLog = Join-Path $dataDir "mysqld.out.log"
$errLog = Join-Path $dataDir "mysqld.err.log"
$process = Start-Process -FilePath $mysqld `
    -ArgumentList "--defaults-file=.tools\mariadb-data\my.ini" `
    -WorkingDirectory $PSScriptRoot `
    -RedirectStandardOutput $outLog `
    -RedirectStandardError $errLog `
    -WindowStyle Hidden `
    -PassThru

$started = $false
for ($i = 0; $i -lt 20; $i++) {
    Start-Sleep -Milliseconds 500

    if ($process.HasExited) {
        break
    }

    $connection = Get-NetTCPConnection -LocalAddress 127.0.0.1 -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue
    if ($connection) {
        $started = $true
        break
    }
}

if (-not $started) {
    Write-Host "MariaDB nao iniciou. Ultimas linhas do log:" -ForegroundColor Red
    if (Test-Path $errLog) {
        Get-Content -Tail 30 -LiteralPath $errLog
    }
    exit 1
}

Write-Host "MariaDB iniciado em 127.0.0.1:3306." -ForegroundColor Green
Get-Process mysqld,mariadbd -ErrorAction SilentlyContinue | Select-Object Id,ProcessName,Path
