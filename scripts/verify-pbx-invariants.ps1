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
$testPath = Join-Path $repositoryRoot 'app\tests\Feature\PbxProvisioningTest.php'

$requiredFiles = @($generatorPath, $browserPath, $productionComposePath, $productionEntrypointPath, $localEntrypointPath, $testPath)
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
$tests = Get-Content -Raw -LiteralPath $testPath

$checks = [ordered]@{
    'Navegador produz E.164 brasileiro' = $browser.Contains('return [`55${national}`]')
    'PBX mantém destino sanitizado no servidor' = $generator.Contains('Set(TH_DEST=\${FILTER(0-9,\${EXTEN})})')
    'PBX acrescenta 55 para telefone nacional' = $generator.Contains('Set(TH_DEST=55\${TH_DEST})')
    'Dial acrescenta TECH da rota' = $generator.Contains('Dial(PJSIP/{$tech}\${TH_DEST}@{$trunkName},40,g)')
    'Rotas respeitam prioridade da empresa' = $generator.Contains("orderBy('tenant_sip_trunks.priority')")
    'Gravação inicia com MixMonitor' = $generator.Contains('MixMonitor(\${RECORDING_ROOT}/\${CALL_RECORDING_FILE},ab)')
    'Volume de runtime é persistente' = $compose.Contains('pbx_runtime:/etc/asterisk/generated')
    'Volume de gravação chega ao Asterisk' = $compose.Contains('pbx_recordings:/var/spool/asterisk/monitor')
    'Segredo AMI permanece privado em producao' = $productionEntrypoint.Contains('chmod 0600 /etc/asterisk/generated/manager_credentials.conf')
    'WAV permanece legivel pelo Laravel em producao' = $productionEntrypoint.Contains('umask 022')
    'WAV permanece legivel pelo Laravel localmente' = $localEntrypoint.Contains('umask 022')
    'Listener AMI permanece implantado' = $compose.Contains('pbx-events:')
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
