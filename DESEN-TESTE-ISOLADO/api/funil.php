<?php
header('Content-Type: application/json');

// Gerenciamento de funil simples via arquivo JSON
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$action = $_GET['action'] ?? '';
$cpf = preg_replace('/\D/', '', $_GET['cpf'] ?? '');

if (empty($cpf)) {
    echo json_encode(['success' => false, 'error' => 'CPF não informado']);
    exit;
}

$filePath = $dataDir . '/funil_' . $cpf . '.json';

if ($action === 'get') {
    if (file_exists($filePath)) {
        $data = json_decode(file_get_contents($filePath), true);
        echo json_encode($data ?: ['step' => 0]);
    } else {
        echo json_encode(['step' => 0]);
    }
} elseif ($action === 'set') {
    $step = intval($_GET['step'] ?? 0);
    $data = ['step' => $step, 'cpf' => $cpf, 'updated_at' => date('c')];
    file_put_contents($filePath, json_encode($data));
    echo json_encode(['success' => true, 'step' => $step]);
} else {
    echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}
