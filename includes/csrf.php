<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf']) || strlen($_SESSION['_csrf']) !== 64) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_token_matches(mixed $provided, mixed $expected): bool
{
    return is_string($provided)
        && is_string($expected)
        && strlen($provided) === 64
        && strlen($expected) === 64
        && hash_equals($expected, $provided);
}

function require_csrf(?array $data = null): void
{
    $data ??= $_POST;
    $provided = $data['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!csrf_token_matches($provided, $_SESSION['_csrf'] ?? null)) {
        abort_request(419, 'Your security token is missing or expired. Refresh the page and try again.');
    }
}
