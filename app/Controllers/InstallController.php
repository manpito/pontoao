<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class InstallController
{
    public function agentScript(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $tenant = $queryParams['tenant'] ?? '';
        $key = $queryParams['key'] ?? '';

        if (empty($tenant) || empty($key)) {
            $script = 'Write-Host "Erro: Parâmetros tenant e key são obrigatórios na URL." -ForegroundColor Red; exit;';
        } else {
            $script = <<<POWERSHELL
# 1. Verificar se Python está instalado
\$python = Get-Command python -ErrorAction SilentlyContinue
if (-not \$python) {
    Write-Host "A instalar Python..."
    # Descarregar e instalar Python 3.12 silenciosamente
    \$pythonUrl = "https://www.python.org/ftp/python/3.12.7/python-3.12.7-amd64.exe"
    \$installer = "\$env:TEMP\python-installer.exe"
    Invoke-WebRequest -Uri \$pythonUrl -OutFile \$installer
    Start-Process -FilePath \$installer -Args "/quiet InstallAllUsers=1 PrependPath=1" -Wait
    Remove-Item \$installer
    # Actualizar PATH na sessão actual
    \$env:Path = [System.Environment]::GetEnvironmentVariable("Path","Machine")
}

# 2. Verificar se NSSM está instalado
\$nssm = Get-Command nssm -ErrorAction SilentlyContinue
if (-not \$nssm) {
    Write-Host "A instalar NSSM..."
    \$nssmUrl = "https://nssm.cc/release/nssm-2.24.zip"
    \$nssmZip = "\$env:TEMP\\nssm.zip"
    Invoke-WebRequest -Uri \$nssmUrl -OutFile \$nssmZip
    Expand-Archive -Path \$nssmZip -DestinationPath "\$env:TEMP\\nssm" -Force
    Copy-Item "\$env:TEMP\\nssm\\nssm-2.24\win64\\nssm.exe" "C:\Windows\System32\\nssm.exe"
    Remove-Item \$nssmZip -Force
    Remove-Item "\$env:TEMP\\nssm" -Recurse -Force
}

# 3. Descarregar o agente
\$agentDir = "C:\pontoao-agent"
if (Test-Path \$agentDir) {
    Write-Host "Agente já instalado. A actualizar..."
    Set-Location \$agentDir
    git pull origin main
} else {
    git clone https://github.com/manpito/pontoao-agent.git \$agentDir
}

# 4. Instalar dependências Python
Set-Location \$agentDir
pip install -r requirements.txt --quiet

# 5. Criar config.ini com as credenciais do tenant
\$config = @"
[pontoao]
url = https://rh.ftl-angola.net
api_key = {$key}
tenant_id = {$tenant}

[agent]
poll_interval_seconds = 300
log_file = agent.log
state_db = state.db
"@
\$config | Out-File -FilePath "\$agentDir\config.ini" -Encoding utf8

# 6. Instalar como serviço Windows
nssm install PontoAO-Agent python "\$agentDir\agent.py"
nssm set PontoAO-Agent AppDirectory \$agentDir
nssm set PontoAO-Agent AppRestartDelay 10000
nssm set PontoAO-Agent AppStdout "\$agentDir\agent.log"
nssm set PontoAO-Agent AppStderr "\$agentDir\agent.log"
nssm start PontoAO-Agent

Write-Host "Agente PontoAO instalado e iniciado com sucesso!"
POWERSHELL;
        }

        $response->getBody()->write($script);
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
