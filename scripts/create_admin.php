<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

if ($argc !== 5) {
    fwrite(STDERR, "Usage: EDUSHARE_BOOTSTRAP_PASSWORD='<strong password>' php scripts/create_admin.php <email> <full-name> <university-id> <department-id>\n");
    exit(2);
}

$email = strtolower(canonical_text((string) $argv[1]));
$fullName = canonical_text((string) $argv[2]);
$universityId = positive_int($argv[3]);
$departmentId = positive_int($argv[4]);
$password = getenv('EDUSHARE_BOOTSTRAP_PASSWORD');
$password = is_string($password) ? $password : '';

$errors = [];
if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $errors[] = 'The email address is invalid.';
}
if (!valid_display_name($fullName)) {
    $errors[] = 'The full name is invalid.';
}
if ($universityId === null || $departmentId === null
    || !validate_department_relationship($universityId, $departmentId)) {
    $errors[] = 'The university and department relationship is invalid.';
}
if (strlen($password) < 10 || strlen($password) > 255
    || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    $errors[] = 'Use a 10–255 character password with at least one letter and one number.';
}
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . "\n");
    }
    exit(2);
}

$existing = db()->prepare('SELECT id FROM users WHERE gmail = ? LIMIT 1');
$existing->bind_param('s', $email);
$existing->execute();
if ($existing->get_result()->fetch_assoc()) {
    fwrite(STDERR, "An account with this email already exists; no changes were made.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$role = 'admin';
$statement = db()->prepare(
    'INSERT INTO users (full_name, gmail, password, user_type, university_id, department_id)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$statement->bind_param('ssssii', $fullName, $email, $hash, $role, $universityId, $departmentId);
$statement->execute();
fwrite(STDOUT, 'Admin account created with ID ' . $statement->insert_id . ". Clear EDUSHARE_BOOTSTRAP_PASSWORD now.\n");
