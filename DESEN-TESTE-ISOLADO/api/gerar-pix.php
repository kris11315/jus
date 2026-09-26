<?php
/**
 * Gera PIX para e-Título
 * POST /api/gerar-pix.php
 */

require_once __DIR__ . '/pix.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'JSON inválido']);
    exit;
}

// Dados do eleitor
$nome = isset($input['nome']) ? trim($input['nome']) : '';
$email = isset($input['email']) ? trim($input['email']) : '';
$cpf = isset($input['cpf']) ? trim($input['cpf']) : '';
$valor = 1790; // R$ 17,90 em centavos

if ($nome === '' || $email === '' || $cpf === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Nome, email e CPF são obrigatórios']);
    exit;
}

// Gera o PIX via Paradise ou Genesys (com fallback automático)
$resultado = pix_criar([
    'nome' => $nome,
    'email' => $email,
    'cpf' => $cpf,
    'valor' => $valor,
    'produto' => 'Comprovante de Justificativa de Voto - e-Título',
    'etapa' => 'pagamento_justificativa',
]);

if ($resultado['ok']) {
    echo json_encode([
        'ok' => true,
        'token' => $resultado['token'],
        'pixCode' => $resultado['pixCode'],
        'qrcodeImage' => $resultado['qrcodeImage'],
        'gateway' => $resultado['gateway'],
    ]);
} else {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'erro' => $resultado['erro'],
        'tentativas' => isset($resultado['tentativas']) ? $resultado['tentativas'] : [],
    ]);
}
