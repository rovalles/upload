<?php
declare(strict_types=1);

final class ApiError extends RuntimeException
{
    public function __construct(public int $status, public string $errorCode, string $message)
    { parent::__construct($message); }
}

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function jsonBody(): array
{
    try { $body = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new ApiError(400, 'INVALID_JSON', 'Expected a JSON object.'); }
    if (!is_array($body) || array_is_list($body)) throw new ApiError(400, 'INVALID_JSON', 'Expected a JSON object.');
    return $body;
}

function checkOrigin(string $allowed, bool $imageView): void
{
    header('Vary: Origin');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    // Direct image URLs may be embedded or opened without an Origin header.
    if ($imageView && $origin === '') return;
    if ($allowed === '') throw new ApiError(503, 'ORIGIN_NOT_CONFIGURED', 'Configure the allowed origin first.');
    if ($origin !== $allowed) throw new ApiError(403, 'ORIGIN_DENIED', 'This origin is not allowed.');
    header('Access-Control-Allow-Origin: ' . $allowed);
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
}
