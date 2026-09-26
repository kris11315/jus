<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['imagemBase64']) || !isset($input['cpf'])) {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
    exit;
}

$imagemBase64 = $input['imagemBase64'];
$cpf = preg_replace('/\D/', '', $input['cpf'] ?? '');

// Remove o prefixo "data:image/png;base64," se existir
if (strpos($imagemBase64, 'data:image') === 0) {
    $imagemBase64 = substr($imagemBase64, strpos($imagemBase64, ',') + 1);
}

// Decodifica a imagem base64
$imagemBinaria = base64_decode($imagemBase64);

if ($imagemBinaria === false) {
    echo json_encode(['success' => false, 'message' => 'Erro ao decodificar imagem']);
    exit;
}

// Cria a pasta se não existir
$pastaComprovantes = dirname(__DIR__) . '/comprovantes';
if (!is_dir($pastaComprovantes)) {
    if (!mkdir($pastaComprovantes, 0755, true)) {
        echo json_encode(['success' => false, 'message' => 'Erro ao criar pasta']);
        exit;
    }
}

// Gera um nome único para o arquivo
$timestamp = date('YmdHis');
$nomeArquivo = 'comprovante_' . $cpf . '_' . $timestamp . '.png';
$caminhoArquivo = $pastaComprovantes . '/' . $nomeArquivo;

// Salva o arquivo
if (file_put_contents($caminhoArquivo, $imagemBinaria) === false) {
    echo json_encode(['success' => false, 'message' => 'Erro ao salvar arquivo']);
    exit;
}

// Retorna o sucesso com o caminho do arquivo
echo json_encode([
    'success' => true,
    'message' => 'Comprovante salvo com sucesso',
    'arquivo' => $nomeArquivo,
    'caminho' => '/etitulo/DESEN-TESTE-ISOLADO/comprovantes/' . $nomeArquivo
], JSON_UNESCAPED_UNICODE);
