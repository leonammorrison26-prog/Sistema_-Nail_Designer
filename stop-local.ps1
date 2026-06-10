Get-Process php -ErrorAction SilentlyContinue | Stop-Process
Get-Process mysqld,mariadbd -ErrorAction SilentlyContinue | Stop-Process

Write-Host "Servidores locais encerrados."
