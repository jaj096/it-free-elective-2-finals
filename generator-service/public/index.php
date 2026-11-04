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
 * Week 14: every /generate call must be signed (HMAC-SHA256), fresh (5 min),
 * single-use (nonce) and carry the personal fields encrypted with
 * XChaCha20-Poly1305. See src/SecureReceiver.php. Plain HTTP is refused when
 * GENERATOR_REQUIRE_HTTPS=true (Caddy terminates TLS in front of this server).
 *
 * Run: php -S 127.0.0.1:8081 -t public public/index.php
 * Tests may point to another config/storage with GENERATOR_ENV_FILE and GENERATOR_STORAGE_DIR.
 */

require __DIR__ . '/../src/MinimalPdf.php';
require __DIR__ . '/../src/SecureReceiver.php';

define('STORAGE', getenv('GENERATOR_STORAGE_DIR') ?: __DIR__ . '/../storage');
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
        'GENERATOR_SLOW_SECONDS' => '15',
        'GENERATOR_REQUIRE_HTTPS' => 'true',
        'GENERATOR_MAX_SKEW_SECONDS' => '300',
        'GENERATOR_ACCEPTED_KEY_IDS' => '',
    ];
    $file = getenv('GENERATOR_ENV_FILE') ?: __DIR__ . '/../.env';
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
    // Structured log to stderr and storage/generator.log.
    // Never includes keys, signatures, ciphertext, names or ID numbers.
    $fields = ['timestamp' => date('c'), 'service' => 'generator'] + $fields;
    $line = json_encode($fields, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    file_put_contents('php://stderr', $line);
    file_put_contents(STORAGE . '/generator.log', $line, FILE_APPEND | LOCK_EX);
}

/** True if this request arrived over TLS (directly, or via the local Caddy proxy). */
function arrived_over_https(): bool
{
    if (($_SERVER['HTTPS'] ?? '') === 'on') {
        return true;
    }
    // Only trust the proxy header from the proxy on this machine.
    $fromLocalProxy = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

    return $fromLocalProxy && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** @return array<string, string> request headers with lower-case names */
function request_headers(): array
{
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (str_starts_with($name, 'HTTP_') && is_string($value)) {
            $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
        }
    }

    return $headers;
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
// The correlation ID comes from the caller, so only keep it if it looks like one (no log injection).
$correlationId = preg_match('/^[A-Za-z0-9-]{8,64}$/', $_SERVER['HTTP_X_CORRELATION_ID'] ?? '') ? $_SERVER['HTTP_X_CORRELATION_ID'] : null;

if ($method === 'GET' && $path === '/health') {
    respond(200, ['status' => 'ok', 'mode' => $mode]);
}

if ($method !== 'POST' || $path !== '/generate') {
    respond(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Unknown endpoint.']]);
}

// 1. Transport: personal data must only arrive over HTTPS.
if (filter_var($config['GENERATOR_REQUIRE_HTTPS'], FILTER_VALIDATE_BOOL) && !arrived_over_https()) {
    log_line(['event' => 'security.rejected', 'reason' => 'HTTPS_REQUIRED', 'correlation_id' => $correlationId]);
    respond(403, ['error' => ['code' => 'HTTPS_REQUIRED', 'message' => 'Use HTTPS.']]);
}

// 2. Authenticity, integrity, freshness, single use, then decryption (see src/SecureReceiver.php).
$receiver = new SecureReceiver(
    SecureReceiver::keysFromConfig($config),
    new NonceStore(STORAGE . '/nonces.json', 2 * (int) $config['GENERATOR_MAX_SKEW_SECONDS']),
    (int) $config['GENERATOR_MAX_SKEW_SECONDS'],
);
try {
    $input = $receiver->open($method, $path, request_headers(), file_get_contents('php://input') ?: '');
} catch (RejectedRequest $e) {
    // Safe log line: reason code, key ID (not secret) and correlation ID only.
    log_line(array_filter([
        'event' => 'security.rejected',
        'reason' => $e->reason,
        'http_status' => $e->status,
        'key_id' => $e->keyId,
        'correlation_id' => $correlationId,
    ], fn ($v) => $v !== null));
    respond($e->status, ['error' => ['code' => $e->reason, 'message' => $e->getMessage()]]);
}

// 3. Validate the (now decrypted) input.
$errors = [];
foreach (['job_key', 'certificate_type', 'requester_name', 'requester_id_number', 'purpose'] as $field) {
    if (trim($input[$field]) === '') {
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

// 4. Idempotency: the same job_key always returns the same PDF once produced.
if (is_file($pdfFile)) {
    log_line(['event' => 'generate.replayed', 'job_key' => $jobKey, 'correlation_id' => $correlationId]);
    respond(201, [
        'job_key' => $jobKey,
        'replayed' => true,
        'pdf_base64' => base64_encode(file_get_contents($pdfFile)),
    ]);
}

// 5. Injected failure modes for testing.
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

// 6. Render the certificate.
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
