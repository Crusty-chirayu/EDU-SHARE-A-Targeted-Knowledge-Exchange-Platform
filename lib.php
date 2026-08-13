<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_method('GET');

// Compatibility route: the material library is canonical at homepage.php.
$query = [];
$mapping = ['uni_id' => 'university_id', 'course' => 'course_id', 'subject' => 'subject_id', 'semester' => 'semester', 'search' => 'search'];
foreach ($mapping as $old => $new) {
    if (isset($_GET[$old]) && is_string($_GET[$old])) {
        $query[$new] = $_GET[$old];
    }
}
$target = 'homepage.php' . ($query === [] ? '' : '?' . http_build_query($query));
redirect($target, 302);
