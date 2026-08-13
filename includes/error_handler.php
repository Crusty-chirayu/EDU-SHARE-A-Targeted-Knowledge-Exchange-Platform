<?php
declare(strict_types=1);

function app_log(string $level, string $message, array $context = []): void
{
    try {
        $logDirectory = (string) app_config('storage_path') . '/logs';
        if (!is_dir($logDirectory) && !@mkdir($logDirectory, 0750, true) && !is_dir($logDirectory)) {
            @error_log('[EDU-SHARE] Unable to create application log directory.');
            return;
        }
    } catch (Throwable) {
        @error_log('[EDU-SHARE] Application logging is unavailable because configuration could not be loaded.');
        return;
    }

    $safeContext = [];
    foreach ($context as $key => $value) {
        if (preg_match('/password|secret|token|cookie|authorization|path|file|sql|query|email|contact|address/i', (string) $key)) {
            $safeContext[$key] = '[redacted]';
        } elseif (is_scalar($value) || $value === null) {
            $safeContext[$key] = $value;
        } else {
            $safeContext[$key] = get_debug_type($value);
        }
    }

    $record = [
        'time' => gmdate('c'),
        'level' => strtoupper($level),
        'message' => $message,
        'request_id' => $_SERVER['EDUSHARE_REQUEST_ID'] ?? null,
        'context' => $safeContext,
    ];

    @error_log(json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, $logDirectory . '/app.log');
}

function render_safe_error(int $status, string $message): never
{
    http_response_code($status);
    if (wants_json()) {
        json_response(['status' => 'error', 'message' => $message], $status);
    }

    header('Content-Type: text/html; charset=UTF-8');
    $safe = h($message);
    try {
        $home = h(app_url('index.php'));
    } catch (Throwable) {
        $home = 'index.php';
    }
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>Error</title></head><body><main><h1>Request could not be completed</h1><p>{$safe}</p><p><a href=\"{$home}\">Return to EDU-SHARE</a></p></main></body></html>";
    exit;
}

function install_error_handlers(): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(static function (Throwable $exception): void {
        if ($exception instanceof AppHttpException) {
            if ($exception->status >= 500) {
                app_log('error', 'HTTP request failed safely', ['status' => $exception->status]);
            }
            render_safe_error($exception->status, $exception->safeMessage);
        }

        // Do not record raw exception text, SQL, stack traces, credentials, or filesystem paths.
        app_log('error', 'Unhandled application exception', ['type' => $exception::class]);
        render_safe_error(500, 'An unexpected error occurred. Please try again later.');
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            app_log('critical', 'Fatal PHP error', ['type' => $error['type']]);
        }
    });
}
