param([string]$Php = 'C:\xampp1\php\php.exe')
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot
if (-not (Test-Path -LiteralPath $Php)) { $Php = (Get-Command php -ErrorAction Stop).Source }
$phpOptions = @()
$loadedExtensions = & $Php -m
foreach ($extensionName in @('intl', 'sqlite3')) {
    if ($loadedExtensions -notcontains $extensionName) { $phpOptions += @('-d', "extension=$extensionName") }
}
if (-not (Test-Path -LiteralPath 'vendor/autoload.php')) { throw 'Instale as dependências com Composer antes de iniciar. Consulte README.md.' }
if (-not (Test-Path -LiteralPath '.env')) { Copy-Item -LiteralPath '.env.example' -Destination '.env' }
& $Php @phpOptions spark migrate --all
if ($LASTEXITCODE -ne 0) { throw 'Falha ao aplicar migrations.' }
Write-Host 'Kairon pronto: http://localhost/ProjetoKairon/'
Write-Host 'Mantenha Apache e MySQL ativos no painel do XAMPP.'
