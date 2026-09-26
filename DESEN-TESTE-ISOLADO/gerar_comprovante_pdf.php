<?php
// Script para preencher o comprovante PDF com dados do usuário

// Cabeçalhos
header('Content-Type: application/json; charset=utf-8');

// Dados recebidos
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Dados não fornecidos']);
    exit;
}

// Verifica se fpdf está disponível
$fpdf_path = __DIR__ . '/../../../vendor/fpdf/fpdf.php';
if (!file_exists($fpdf_path)) {
    // Se não tiver FPDF, usa mPDF se disponível
    $mpdf_path = __DIR__ . '/../../../vendor/mpdf/mpdf/src/Mpdf.php';
    if (!file_exists($mpdf_path)) {
        http_response_code(500);
        echo json_encode(['error' => 'Bibliotecas de PDF não instaladas']);
        exit;
    }

    // Usa mPDF
    require_once $mpdf_path;
    $mpdf = new \Mpdf\Mpdf();

    // Carrega PDF existente
    $pdf_input = __DIR__ . '/desen/atendimento-else/images/comprovante.pdf';
    $mpdf->setSourceFile($pdf_input);
    $page = $mpdf->importPage(1);
    $mpdf->AddPage();
    $mpdf->useTemplate($page);

    // Adiciona texto nos campos (coordenadas em mm)
    $mpdf->SetFont('Arial', '', 10);
    $mpdf->SetTextColor(0, 0, 0);

    // Exemplo de campos (ajuste as coordenadas conforme necessário)
    $mpdf->WriteFixedPosHTML($data['nome'] ?? '', 30, 50, 150, 10);
    $mpdf->WriteFixedPosHTML($data['cpf'] ?? '', 30, 65, 150, 10);
    $mpdf->WriteFixedPosHTML($data['nasc'] ?? '', 30, 80, 150, 10);
    $mpdf->WriteFixedPosHTML($data['mae'] ?? '', 30, 95, 150, 10);

    // Output como base64
    $pdf_content = $mpdf->Output('', 'S');

    echo json_encode([
        'success' => true,
        'pdf_base64' => base64_encode($pdf_content),
        'filename' => 'Comprovante_' . ($data['cpf'] ?? 'eleitor') . '.pdf'
    ]);
    exit;
}

// Se tiver FPDF
require_once $fpdf_path;

class FPDF_Preenchido extends FPDF {
    public function addField($x, $y, $text) {
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($x, $y);
        $this->Cell(0, 5, $text);
    }
}

// Cria PDF
$pdf = new FPDF_Preenchido();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 10);

// Adiciona os dados do usuário (coordenadas aproximadas - ajustar depois)
$pdf->addField(30, 50, $data['nome'] ?? '');
$pdf->addField(30, 65, $data['cpf'] ?? '');
$pdf->addField(30, 80, $data['nasc'] ?? '');
$pdf->addField(30, 95, $data['mae'] ?? '');
$pdf->addField(30, 110, $data['turno'] ?? '');
$pdf->addField(30, 125, $data['justificativa'] ?? '');
$pdf->addField(30, 140, $data['email'] ?? '');

// Output
$filename = 'Comprovante_' . ($data['cpf'] ?? 'eleitor') . '.pdf';
$pdf_content = $pdf->Output('S');

echo json_encode([
    'success' => true,
    'pdf_base64' => base64_encode($pdf_content),
    'filename' => $filename
]);
