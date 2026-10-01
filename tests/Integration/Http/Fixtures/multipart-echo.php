<?php

/**
 * Router for `php -S`: answers with what PHP's own multipart parser made of the request.
 *
 * Fields come back as given; each file as its client name, type, size and contents in base64,
 * so a test can compare bytes.
 */
$files = [];
foreach ($_FILES as $field => $file) {
    foreach ((array) $file['name'] as $i => $_) {
        $pick = static fn (string $key) => is_array($file[$key]) ? $file[$key][$i] : $file[$key];
        $files[] = [
            'field'    => $field,
            'name'     => $pick('name'),
            'type'     => $pick('type'),
            'error'    => $pick('error'),
            'contents' => base64_encode((string) @file_get_contents($pick('tmp_name'))),
        ];
    }
}

header('Content-Type: application/json');
echo json_encode(['post' => $_POST, 'files' => $files]);
