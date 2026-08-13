<?php
declare(strict_types=1);

final class AppHttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $safeMessage,
        string $internalMessage = ''
    ) {
        parent::__construct($internalMessage !== '' ? $internalMessage : $safeMessage);
    }
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function wants_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    return str_contains($accept, 'application/json') || str_contains($contentType, 'application/json');
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function abort_request(int $status, string $safeMessage, string $internalMessage = ''): never
{
    throw new AppHttpException($status, $safeMessage, $internalMessage);
}

function require_method(string ...$allowedMethods): void
{
    $method = request_method();
    $allowedMethods = array_map('strtoupper', $allowedMethods);
    if (!in_array($method, $allowedMethods, true)) {
        header('Allow: ' . implode(', ', $allowedMethods));
        abort_request(405, 'This request method is not allowed.');
    }
}

function decode_json_object(string $raw): array
{
    if (strlen($raw) > 65536) {
        abort_request(400, 'The request body is invalid.');
    }

    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        abort_request(400, 'Malformed JSON request.');
    }

    if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) {
        abort_request(400, 'The request body must be a JSON object.');
    }

    return $data;
}

function request_data(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_contains($contentType, 'application/json')) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        abort_request(400, 'The request body is invalid.');
    }

    return decode_json_object($raw);
}

function positive_int(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/', $value)) {
        return null;
    }

    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $number === false ? null : $number;
}

function redirect(string $path, int $status = 303): never
{
    header('Location: ' . app_url($path), true, $status);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($messages) ? $messages : [];
}

function client_identifier_hash(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $ip);
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'");
}
