param(
    [switch]$RunTests
)

$ErrorActionPreference = 'Stop'
$repositoryRoot = Split-Path -Parent $PSScriptRoot
$generatorPath = Join-Path $repositoryRoot 'app\app\Services\Pbx\PbxConfigGenerator.php'
$browserPath = Join-Path $repositoryRoot 'app\resources\js\app.js'
$productionComposePath = Join-Path $repositoryRoot 'production\compose.yaml'
$productionEntrypointPath = Join-Path $repositoryRoot 'production\asterisk\entrypoint.sh'
$localEntrypointPath = Join-Path $repositoryRoot 'docker\asterisk\entrypoint.sh'
$rtpPath = Join-Path $repositoryRoot 'docker\asterisk\config\rtp.conf'
$turnFactoryPath = Join-Path $repositoryRoot 'app\app\Services\Pbx\TurnCredentialFactory.php'
$turnEntrypointPath = Join-Path $repositoryRoot 'production\coturn\entrypoint.sh'
$certbotEntrypointPath = Join-Path $repositoryRoot 'production\certbot\entrypoint.sh'
$certbotDockerfilePath = Join-Path $repositoryRoot 'production\Dockerfile.certbot'
$testPath = Join-Path $repositoryRoot 'app\tests\Feature\PbxProvisioningTest.php'

$requiredFiles = @($generatorPath, $browserPath, $productionComposePath, $productionEntrypointPath, $localEntrypointPath, $rtpPath, $turnFactoryPath, $turnEntrypointPath, $certbotEntrypointPath, $certbotDockerfilePath, $testPath)
foreach ($file in $requiredFiles) {
    if (-not (Test-Path -LiteralPath $file)) {
        throw "Arquivo crítico ausente: $file"
    }
}

$generator = Get-Content -Raw -LiteralPath $generatorPath
$browser = Get-Content -Raw -LiteralPath $browserPath
$compose = Get-Content -Raw -LiteralPath $productionComposePath
$productionEntrypoint = Get-Content -Raw -LiteralPath $productionEntrypointPath
$localEntrypoint = Get-Content -Raw -LiteralPath $localEntrypointPath
$rtp = Get-Content -Raw -LiteralPath $rtpPath
$turnFactory = Get-Content -Raw -LiteralPath $turnFactoryPath
$turnEntrypoint = Get-Content -Raw -LiteralPath $turnEntrypointPath
$certbotEntrypoint = Get-Content -Raw -LiteralPath $certbotEntrypointPath
$certbotDockerfile = Get-Content -Raw -LiteralPath $certbotDockerfilePath
$tests = Get-Content -Raw -LiteralPath $testPath

$checks = [ordered]@{
    'Navegador produz E.164 brasileiro' = $browser.Contains('return [`55${national}`]')
    'PBX mantém destino sanitizado no servidor' = $generator.Contains('Set(TH_DEST=\${FILTER(0-9,\${EXTEN})})')
    'PBX acrescenta 55 para telefone nacional' = $generator.Contains('Set(TH_DEST=55\${TH_DEST})')
    'Dial acrescenta TECH da rota' = $generator.Contains('Dial(PJSIP/{$tech}\${TH_DEST}@{$trunkName},40,g)')
    'Rotas respeitam prioridade da empresa' = $generator.Contains("orderBy('tenant_sip_trunks.priority')")
    'Gravação inicia com MixMonitor' = $generator.Contains('MixMonitor(\${RECORDING_ROOT}/\${CALL_RECORDING_FILE},ab)')
    'Trunk preserva simetria RTP/NAT' = $generator.Contains('force_rport=yes') -and $generator.Contains('rewrite_contact=yes') -and $generator.Contains('rtp_symmetric=yes')
    'Trunk anuncia IP público no SDP' = $generator.Contains('media_address={$mediaAddress}') -and $compose.Contains('PBX_PUBLIC_IP: ${PBX_PUBLIC_IP:?Defina PBX_PUBLIC_IP no Easypanel}') -and $tests.Contains('media_address=203.0.113.10')
    'Teste interno não alcança rota TECH' = $generator.Contains('exten => *900,1,NoOp(WebRTC audio check') -and $generator.Contains('same => n,Echo()')
    'Faixa RTP suporta capacidade planejada' = $rtp.Contains('rtpend = 10299') -and $compose.Contains('10101-10299:10101-10299/udp')
    'TURN usa credenciais temporárias' = $turnFactory.Contains('hash_hmac') -and $turnFactory.Contains("now()->addSeconds")
    'TURN não vira relay aberto' = $turnEntrypoint.Contains('--use-auth-secret') -and $turnEntrypoint.Contains('--no-cli')
    'Segredo TURN não chega ao JavaScript' = -not $browser.Contains('TURN_AUTH_SECRET')
    'Volume de runtime é persistente' = $compose.Contains('pbx_runtime:/etc/asterisk/generated')
    'Volume de gravação chega ao Asterisk' = $compose.Contains('pbx_recordings:/var/spool/asterisk/monitor')
    'Segredo AMI permanece privado em producao' = $productionEntrypoint.Contains('chmod 0600 /etc/asterisk/generated/manager_credentials.conf')
    'WAV permanece legivel pelo Laravel em producao' = $productionEntrypoint.Contains('umask 022')
    'WAV permanece legivel pelo Laravel localmente' = $localEntrypoint.Contains('umask 022')
    'Listener AMI permanece implantado' = $compose.Contains('pbx-events:')
    'Coturn permanece implantado' = $compose.Contains('turn:') -and $compose.Contains('49160-49359:49160-49359/udp')
    'Certificado TURN usa desafio DNS restrito' = $compose.Contains('turn-certbot:') -and $compose.Contains('CLOUDFLARE_DNS_API_TOKEN') -and $certbotDockerfile.Contains('certbot/dns-cloudflare') -and $certbotEntrypoint.Contains('--dns-cloudflare')
    'Renovação TURN não usa socket Docker' = -not $compose.Contains('/var/run/docker.sock') -and $compose.Contains('pid: service:turn') -and $certbotEntrypoint.Contains('kill -USR2 1')
    'Chave TURN permanece legível somente pelo Coturn' = $certbotEntrypoint.Contains('chmod 0750') -and $certbotEntrypoint.Contains('chmod 0640') -and $certbotEntrypoint.Contains('chown root:65534')
    'Teste protege TECH e E.164' = $tests.Contains('Dial(PJSIP/8033${TH_DEST}@trunk-')
}

$failed = @($checks.GetEnumerator() | Where-Object { -not $_.Value })
$checks.GetEnumerator() | ForEach-Object {
    $mark = if ($_.Value) { '[OK]' } else { '[FALHA]' }
    Write-Host "$mark $($_.Key)"
}

if ($failed.Count -gt 0) {
    throw "Uma ou mais proteções críticas do PABX foram removidas. Não publique."
}

if ($RunTests) {
    Push-Location $repositoryRoot
    try {
        docker compose exec -T -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: app php artisan test --compact
        if ($LASTEXITCODE -ne 0) { throw 'Os testes PHP falharam.' }
        docker compose run --rm assets npm run build
        if ($LASTEXITCODE -ne 0) { throw 'A compilação dos assets falhou.' }
    }
    finally {
        Pop-Location
    }
}

Write-Host '[OK] Guardrails críticos validados. O deploy ainda exige a checagem operacional pós-publicação.'
