<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Http.php';
require dirname(__DIR__) . '/src/ImageValidator.php';
require dirname(__DIR__) . '/src/ImageStorage.php';
require dirname(__DIR__) . '/src/ImageHandlers.php';

header('X-Content-Type-Options: nosniff');
try {
    $config = require dirname(__DIR__) . (is_file(dirname(__DIR__) . '/config.php') ? '/config.php' : '/config.example.php');
    $method = $_SERVER['REQUEST_METHOD'];
    $basePath = rtrim(parse_url($config['base_url'], PHP_URL_PATH) ?? '', '/');
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($basePath !== '' && !str_starts_with($uri, $basePath . '/')) throw new ApiError(404, 'ROUTE_NOT_FOUND', 'Route not found.');
    $route = substr($uri, strlen($basePath));
    $collection = $route === '/api/images';
    $item = str_starts_with($route, '/api/images/') ? ImageStorage::path(rawurldecode(substr($route, 12))) : null;
    checkOrigin($config['allowed_origin'], $item !== null && in_array($method, ['GET', 'HEAD'], true));
    if (!$collection && $item === null) throw new ApiError(404, 'ROUTE_NOT_FOUND', 'Route not found.');
    if ($method === 'OPTIONS') { http_response_code(204); exit; }
    $validator = new ImageValidator($config['max_image_bytes']);
    $storage = new ImageStorage($config['storage_dir'], rtrim($config['base_url'], '/'), $validator);

    if ($collection) {
        match ($method) {
            'GET' => listImages($storage),
            'POST' => uploadImages($storage, $validator, $config),
            default => null,
        };
    } else {
        match ($method) {
            'GET', 'HEAD' => viewImage($storage, $item, $method),
            'PUT' => replaceImage($storage, $item, $config),
            'PATCH' => moveImage($storage, $item),
            'DELETE' => deleteImage($storage, $item),
            default => null,
        };
    }
    header('Allow: ' . ($collection ? 'GET, POST, OPTIONS' : 'GET, HEAD, PUT, PATCH, DELETE, OPTIONS'));
    throw new ApiError(405, 'METHOD_NOT_ALLOWED', 'This method is not supported for this route.');
} catch (ApiError $error) {
    respond(['error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]], $error->status);
} catch (Throwable $error) {
    error_log((string) $error);
    respond(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'The service could not complete the request.']], 500);
}
