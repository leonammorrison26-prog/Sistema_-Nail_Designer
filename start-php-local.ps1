$ErrorActionPreference = "Stop"

$env:DB_HOST = "127.0.0.1"
$env:DB_PORT = "3306"
$env:DB_NAME = "salao_sammy"
$env:DB_USER = "root"
$env:DB_PASS = "root"

# Obtém o caminho absoluto para o executável do PHP
$phpExe = Join-Path $PSScriptRoot ".tools\php\php.exe"

if (-not (Test-Path $phpExe)) {
    Write-Error "Executável do PHP não encontrado em: $phpExe"
    exit
}

# Tenta desbloquear todos os arquivos na pasta do PHP recursivamente (evita Acesso Negado)
Write-Host "Verificando permissoes dos arquivos do PHP..." -ForegroundColor Yellow
$phpDir = Split-Path $phpExe -Parent
Get-ChildItem -Path $phpDir -Recurse | Unblock-File -ErrorAction SilentlyContinue

Write-Host "Iniciando servidor PHP em http://localhost:8000..." -ForegroundColor Cyan
try {
    # O operador '&' executa o comando e mantém o terminal ocupado.
    # Se o erro "Acesso negado" persistir, verifique as permissões da pasta e o antivírus.
    & $phpExe -S localhost:8000
} catch {
    Write-Error "Falha ao iniciar o servidor PHP: $($_.Exception.Message). Verifique as permissões da pasta e o antivírus."
}
