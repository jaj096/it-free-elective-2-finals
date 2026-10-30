<?php

declare(strict_types=1);

/*
 * Mock Certificate Generator Service (Project 21).
 *
 * A deliberately small, dependency-free service that the Laravel queue worker
 * calls to produce certificate PDFs. Its behaviour is controlled by
 * GENERATOR_MODE in ../.env so failures can be injected for testing:
 *
 *   success                  always returns a PDF
 *   fail_twice_then_succeed  503 on the first two calls per job_key, then a PDF
 *   always_503               always "service unavailable" (transient)
 *   always_422               always rejects the input (permanent)
 *   slow                     sleeps GENERATOR_SLOW_SECONDS before answering
 *
 * The .env file is re-read on every request, so the mode can be switched
 * without restarting the server.
 *
 * Run: php -S 127.0.0.1:8081 -t public public/index.php
 */

require __DIR__ . '/../src/MinimalPdf.php';

const STORAGE = __DIR__ . '/../storage';
const ALLOWED_TYPES = [
    'enrollment' => 'Certificate of Enrollment',
    'good_moral' => 'Certificate of Good Moral Character',
    'grades' => 'Certification of Grades',
    'graduation' => 'Certificate of Graduation',
];

function env_config(): array
{
    $defaults = [
        'GENERATOR_MODE' => 'success',
        'GENERATOR_SERVICE_KEY' => '',
        'GENERATOR_SLOW_SECONDS' => '15',
    ];
    $file = __DIR__ . '/../.env';
    if (is_file($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            $defaults[$k] = trim($v, "\"'");
        }
    }

    return $defaults;
}

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function log_line(array $fields): void
{
    // Structured log to stderr; never includes the service key or names.
    $fields = ['timestamp' => date('c'), 'service' => 'generator'] + $fields;
    file_put_contents('php://stderr', json_encode($fields, JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

/** Counts calls per job_key so fail_twice_then_succeed can fail exactly twice. */
function bump_attempts(string $jobKey): int
{
    $file = STORAGE . '/attempts.json';
    $fp = fopen($file, 'c+');
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
    $data[$jobKey] = ($data[$jobKey] ?? 0) + 1;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);

    return $data[$jobKey];
}

@mkdir(STORAGE . '/pdf', 0777, true);
$config = env_config();
$mode = $config['GENERATOR_MODE'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$correlationId = $_SERVER['HTTP_X_CORRELATION_ID'] ?? null;

if ($method === 'GET' && $path === '/health') {
    respond(200, ['status' => 'ok', 'mode' => $mode]);
}

if ($method !== 'POST' || $path !== '/generate') {
    respond(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Unknown endpoint.']]);
}

// 1. Authenticate the caller with the shared service key (from .env, never in code).
$expectedKey = $config['GENERATOR_SERVICE_KEY'];
$givenKey = $_SERVER['HTTP_X_SERVICE_KEY'] ?? '';
if ($expectedKey === '' || !hash_equals($expectedKey, $givenKey)) {
    log_line(['event' => 'auth.rejected', 'correlation_id' => $correlationId]);
    respond(401, ['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Invalid service key.']]);
}

// 2. Validate input.
$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    respond(400, ['error' => ['code' => 'BAD_REQUEST', 'message' => 'Body must be JSON.']]);
}
$errors = [];
foreach (['job_key', 'certificate_type', 'requester_name', 'requester_id_number', 'purpose'] as $field) {
    if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '') {
        $errors[$field] = 'Required.';
    }
}
if (!isset($errors['certificate_type']) && !isset(ALLOWED_TYPES[$input['certificate_type']])) {
    $errors['certificate_type'] = 'No template for this certificate type.';
}
if ($errors) {
    log_line(['event' => 'generate.invalid', 'correlation_id' => $correlationId, 'fields' => array_keys($errors)]);
    respond(422, ['error' => ['code' => 'VALIDATION_FAILED', 'message' => 'Invalid input.', 'fields' => $errors]]);
}

$jobKey = preg_replace('/[^A-Za-z0-9-]/', '', $input['job_key']);
$attempt = bump_attempts($jobKey);
$pdfFile = STORAGE . "/pdf/{$jobKey}.pdf";

// 3. Idempotency: the same job_key always returns the same PDF once produced.
if (is_file($pdfFile)) {
    log_line(['event' => 'generate.replayed', 'job_key' => $jobKey, 'correlation_id' => $correlationId]);
    respond(201, [
        'job_key' => $jobKey,
        'replayed' => true,
        'pdf_base64' => base64_encode(file_get_contents($pdfFile)),
    ]);
}

// 4. Injected failure modes for testing.
switch ($mode) {
    case 'always_503':
        log_line(['event' => 'generate.unavailable', 'mode' => $mode, 'job_key' => $jobKey, 'attempt' => $attempt]);
        respond(503, ['error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'Generator is temporarily unavailable.']]);
    case 'fail_twice_then_succeed':
        if ($attempt <= 2) {
            log_line(['event' => 'generate.unavailable', 'mode' => $mode, 'job_key' => $jobKey, 'attempt' => $attempt]);
            respond(503, ['error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'Generator is temporarily unavailable.']]);
        }
        break;
    case 'always_422':
        log_line(['event' => 'generate.rejected', 'mode' => $mode, 'job_key' => $jobKey, 'attempt' => $attempt]);
        respond(422, ['error' => ['code' => 'TEMPLATE_UNAVAILABLE', 'message' => 'The certificate template could not be rendered for this record.']]);
    case 'slow':
        sleep((int) $config['GENERATOR_SLOW_SECONDS']);
        break;
}

// 5. Render the certificate.
$title = ALLOWED_TYPES[$input['certificate_type']];
$pdf = MinimalPdf::render([
    ['text' => 'UNIVERSITY OF LA SALETTE, INC. (SAMPLE)', 'size' => 14, 'y' => 700],
    ['text' => $title, 'size' => 22, 'y' => 620],
    ['text' => 'This certifies that', 'size' => 12, 'y' => 560],
    ['text' => $input['requester_name'], 'size' => 18, 'y' => 525],
    ['text' => 'ID No. ' . $input['requester_id_number'], 'size' => 12, 'y' => 495],
    ['text' => 'Purpose: ' . $input['purpose'], 'size' => 12, 'y' => 450],
    ['text' => 'Generated ' . date('F j, Y'), 'size' => 10, 'y' => 380],
    ['text' => 'Job ' . $jobKey, 'size' => 8, 'y' => 80],
    ['text' => 'FOR CLASS DEMONSTRATION ONLY - NOT AN OFFICIAL DOCUMENT', 'size' => 9, 'y' => 60],
]);
file_put_contents($pdfFile, $pdf);

log_line(['event' => 'generate.succeeded', 'mode' => $mode, 'job_key' => $jobKey, 'attempt' => $attempt, 'bytes' => strlen($pdf)]);
respond(201, [
    'job_key' => $jobKey,
    'replayed' => false,
    'pdf_base64' => base64_encode($pdf),
]);
