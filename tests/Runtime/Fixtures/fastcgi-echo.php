<?php

declare(strict_types=1);

// FastCgiClientTest: answers with what PHP-FPM received, so the test compares it with what the client sent.
header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'uri' => $_SERVER['REQUEST_URI'] ?? null,
    'query' => $_SERVER['QUERY_STRING'] ?? null,
    'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
    'headers' => array_filter($_SERVER, static fn(int|string $name): bool => is_string($name) && str_starts_with($name, 'HTTP_X_'), ARRAY_FILTER_USE_KEY),
    'body' => file_get_contents('php://input'),
], JSON_THROW_ON_ERROR);
