# Melhora a imagem do comprovante usando Vertex AI Imagen

$PROJECT_ID = "project-1cbb1251-7dcc-4c1c-a30"
$LOCATION = "us-central1"
$MODEL = "imagen-4.0-ultra-generate-001"
$OUTPUT_DIR = "desen\atendimento-else\images"
$OUTPUT_FILE = "$OUTPUT_DIR\comprovante_melhorada.png"

Write-Host "🚀 Obtendo token do gcloud..." -ForegroundColor Cyan
$token = gcloud auth application-default print-access-token 2>$null

if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Erro ao obter token. Certifique-se que gcloud está configurado:" -ForegroundColor Red
    Write-Host "  gcloud auth application-default login"
    exit 1
}

Write-Host "✅ Token obtido" -ForegroundColor Green
Write-Host "📤 Enviando imagem para Vertex AI..." -ForegroundColor Cyan

$url = "https://aiplatform.googleapis.com/v1/projects/$PROJECT_ID/locations/$LOCATION/publishers/google/models/${MODEL}:predict"

$body = @{
    instances = @(
        @{
            prompt = "Melhore esta imagem de comprovante eleitoral: remova distorção, corrija proporções (não deixe gordo), aumente nitidez, melhore legibilidade. Retorne em alta resolução."
        }
    )
    parameters = @{
        sampleCount = 1
        aspectRatio = "3:4"
    }
} | ConvertTo-Json

$headers = @{
    Authorization = "Bearer $token"
    "Content-Type" = "application/json"
}

try {
    $response = Invoke-WebRequest -Uri $url -Method POST -Headers $headers -Body $body -ErrorAction Stop
    $data = $response.Content | ConvertFrom-Json

    if ($data.predictions.Count -eq 0) {
        Write-Host "❌ Nenhuma imagem retornada da API" -ForegroundColor Red
        exit 1
    }

    $imgBase64 = $data.predictions[0].bytesBase64Encoded
    $imgBytes = [Convert]::FromBase64String($imgBase64)

    # Cria diretório se não existir
    if (-not (Test-Path $OUTPUT_DIR)) {
        New-Item -ItemType Directory -Path $OUTPUT_DIR -Force | Out-Null
    }

    [IO.File]::WriteAllBytes($OUTPUT_FILE, $imgBytes)

    Write-Host "✅ Imagem melhorada salva em:" -ForegroundColor Green
    Write-Host "   $OUTPUT_FILE" -ForegroundColor Yellow
    Write-Host "   Tamanho: $([math]::Round($imgBytes.Length / 1KB, 1)) KB" -ForegroundColor Green

} catch {
    Write-Host "❌ Erro na API: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host $_.Exception.Response.Content -ForegroundColor Red
    exit 1
}
