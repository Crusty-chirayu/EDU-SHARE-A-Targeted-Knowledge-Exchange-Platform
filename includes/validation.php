<?php
declare(strict_types=1);

function canonical_text(mixed $value, bool $multiline = false): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = str_replace(["\r\n", "\r"], "\n", trim($value));
    if ($multiline) {
        $value = preg_replace('/[\t ]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? '';
        return $value;
    }

    return preg_replace('/\s+/u', ' ', $value) ?? '';
}

function valid_display_name(string $name): bool
{
    return $name !== ''
        && mb_strlen($name) <= 100
        && preg_match("/^[\p{L}\p{M}][\p{L}\p{M} .'-]*$/u", $name) === 1;
}

function valid_taxonomy_name(string $name): bool
{
    return $name !== ''
        && mb_strlen($name) <= 150
        && !preg_match('/[<>`"\\x00-\\x1F\\x7F]/u', $name);
}

function validate_registration_input(array $input): array
{
    $values = [
        'role' => canonical_text($input['role'] ?? ''),
        'name' => canonical_text($input['name'] ?? ''),
        'email' => strtolower(canonical_text($input['email'] ?? '')),
        'password' => is_string($input['password'] ?? null) ? $input['password'] : '',
        'university_id' => positive_int($input['university_id'] ?? null),
        'department_id' => positive_int($input['department_id'] ?? null),
        'branch' => canonical_text($input['branch'] ?? ''),
        'year' => positive_int($input['year'] ?? null),
        'contact' => canonical_text($input['contact'] ?? ''),
        'address' => canonical_text($input['address'] ?? ''),
    ];
    $errors = [];

    if (!in_array($values['role'], ['student', 'teacher'], true)) {
        $errors['role'] = 'Choose either student or teacher/contributor.';
    }
    if (!valid_display_name($values['name'])) {
        $errors['name'] = 'Enter a valid name of at most 100 characters.';
    }
    if (strlen($values['email']) > 254 || filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (strlen($values['password']) < 10
        || strlen($values['password']) > 255
        || !preg_match('/[A-Za-z]/', $values['password'])
        || !preg_match('/[0-9]/', $values['password'])) {
        $errors['password'] = 'Use 10–255 characters with at least one letter and one number.';
    }
    if ($values['university_id'] === null) {
        $errors['university_id'] = 'Choose a university.';
    }
    if ($values['department_id'] === null) {
        $errors['department_id'] = 'Choose a department.';
    }
    if ($values['contact'] !== '') {
        $phone = preg_replace('/[\s().-]+/', '', $values['contact']) ?? '';
        if (!preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
            $errors['contact'] = 'Enter a valid phone number or leave it blank.';
        } else {
            $values['contact'] = $phone;
        }
    }
    if (mb_strlen($values['address']) > 255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $values['address'])) {
        $errors['address'] = 'Address must be at most 255 characters.';
    }

    if ($values['role'] === 'student') {
        if ($values['branch'] === '' || mb_strlen($values['branch']) > 100 || !valid_taxonomy_name($values['branch'])) {
            $errors['branch'] = 'Enter a valid branch of at most 100 characters.';
        }
        if ($values['year'] === null || $values['year'] > 8) {
            $errors['year'] = 'Year of study must be between 1 and 8.';
        }
    } else {
        $values['branch'] = null;
        $values['year'] = null;
    }

    return [$values, $errors];
}

function validate_upload_metadata(array $input): array
{
    $values = [
        'title' => canonical_text($input['title'] ?? ''),
        'description' => canonical_text($input['description'] ?? '', true),
        'university_id' => positive_int($input['university_id'] ?? null),
        'department_id' => positive_int($input['department_id'] ?? ($input['department'] ?? null)),
        'course_id' => positive_int($input['course_id'] ?? ($input['course'] ?? null)),
        'subject_id' => positive_int($input['subject_id'] ?? null),
        'semester' => positive_int($input['semester'] ?? null),
    ];
    $errors = [];

    if ($values['title'] === '' || mb_strlen($values['title']) > 200 || preg_match('/[\x00-\x1F\x7F]/', $values['title'])) {
        $errors['title'] = 'Title is required and must be at most 200 characters.';
    }
    if (mb_strlen($values['description']) > 5000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $values['description'])) {
        $errors['description'] = 'Description must be at most 5,000 characters.';
    }
    foreach (['university_id', 'department_id', 'course_id', 'subject_id'] as $field) {
        if ($values[$field] === null) {
            $errors[$field] = 'Choose a valid academic option.';
        }
    }
    if ($values['semester'] === null || $values['semester'] > 12) {
        $errors['semester'] = 'Semester must be between 1 and 12.';
    }

    return [$values, $errors];
}

function validate_academic_relationships(array $values): bool
{
    $statement = db()->prepare(
        'SELECT 1
           FROM departments d
           JOIN courses c ON c.department_id = d.id
           JOIN subjects s ON s.course_id = c.id AND s.department_id = d.id
          WHERE d.id = ? AND d.university_id = ?
            AND c.id = ? AND s.id = ? AND s.semester = ?
          LIMIT 1'
    );
    $statement->bind_param(
        'iiiii',
        $values['department_id'],
        $values['university_id'],
        $values['course_id'],
        $values['subject_id'],
        $values['semester']
    );
    $statement->execute();
    return $statement->get_result()->num_rows === 1;
}

function validate_department_relationship(int $universityId, int $departmentId): bool
{
    $statement = db()->prepare('SELECT 1 FROM departments WHERE id = ? AND university_id = ? LIMIT 1');
    $statement->bind_param('ii', $departmentId, $universityId);
    $statement->execute();
    return $statement->get_result()->num_rows === 1;
}
