<?php
// procesar_pdf.php

// Desactivar cualquier salida que rompa JSON
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Forzar JSON
header("Content-Type: application/json");

// Validar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Método no permitido"]);
    exit;
}

// Validar PDF
if (!isset($_FILES['pdfFile']) || $_FILES['pdfFile']['error'] !== 0) {
    echo json_encode(["error" => "No se recibió el PDF correctamente"]);
    exit;
}

// API Key y modelo
$apiKey = $_POST['apiKey'] ?? '';
$model  = $_POST['model'] ?? 'gpt-4o-mini';

if (!$apiKey) {
    echo json_encode(["error" => "Falta API Key de OpenAI"]);
    exit;
}

// Guardar PDF temporal
$tempPdf = 'temp_' . time() . '.pdf';
if (!move_uploaded_file($_FILES['pdfFile']['tmp_name'], $tempPdf)) {
    echo json_encode(["error" => "No se pudo guardar el PDF temporal"]);
    exit;
}

// Extraer texto del PDF
$text = '';
try {
    require 'vendor/autoload.php';
    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($tempPdf);
    $text = $pdf->getText();
} catch (Exception $e) {
    unlink($tempPdf);
    echo json_encode(["error" => "No se pudo extraer texto del PDF"]);
    exit;
}
unlink($tempPdf);

// Limitar texto a 15000 caracteres
$text = substr($text, 0, 15000);

// Preparar prompt para OpenAI
$prompt = "Resume este artículo científico completo:
1. Resumen breve (3–5 oraciones)
2. Metodología
3. Conclusión

Texto del artículo:
$text";

$data = [
    "model" => $model,
    "messages" => [
        ["role" => "system", "content" => "Eres un asistente que resume artículos científicos."],
        ["role" => "user", "content" => $prompt]
    ]
];

// Llamada a la API de OpenAI
$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $apiKey",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(["error" => "cURL error: $err"]);
    exit;
}

$json = json_decode($response, true);
$resumen = $json['choices'][0]['message']['content'] ?? "(No se pudo generar resumen)";

// Guardar resumen en archivo
if (!is_dir("resumenes")) mkdir("resumenes");
$resumenFile = "resumenes/resumen_" . time() . ".txt";
file_put_contents($resumenFile, $resumen);

// Limpiar buffer y enviar JSON
ob_end_clean();
echo json_encode([
    "resumen" => $resumen,
    "archivoResumen" => $resumenFile
]);
exit;
