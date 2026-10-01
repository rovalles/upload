<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Http.php';
require dirname(__DIR__) . '/src/ImageValidator.php';
require dirname(__DIR__) . '/src/ImageStorage.php';

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

    if ($collection && $method === 'GET') {
        $root = ImageStorage::path($_GET['root'] ?? null);
        $path = ImageStorage::path($_GET['path'] ?? '', true);
        respond(['images' => $storage->listing($root . ($path === '' ? '' : '/' . $path))]);
    }
    if ($collection && $method === 'POST') {
        $postLimit = ini_get('post_max_size');
        $unit = strtolower(substr($postLimit, -1));
        $postBytes = (int) $postLimit * match ($unit) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
        if ($postBytes > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postBytes)
            throw new ApiError(413, 'REQUEST_TOO_LARGE', 'The request exceeds the PHP post_max_size limit.');
        try { $sets = json_decode($_POST['metadata'] ?? '', true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new ApiError(400, 'INVALID_METADATA', 'Send metadata as a JSON array in a multipart form.'); }
        if (!is_array($sets) || !array_is_list($sets) || !$sets) throw new ApiError(422, 'INVALID_METADATA', 'Metadata must be a nonempty array of sets.');
        $files = $_FILES['images'] ?? [];
        if (!is_array($files) || !is_array($files['error'] ?? []) || !is_array($files['tmp_name'] ?? []))
            throw new ApiError(422, 'INVALID_FILES', 'Use images[set][item] file fields.');
        foreach (($files['error'] ?? []) as $group) {
            if (!is_array($group)) throw new ApiError(422, 'INVALID_FILES', 'Use images[set][item] file fields.');
        }
        $uploads = []; $seen = [];
        foreach ($sets as $s => $set) {
            if (!is_array($set)) throw new ApiError(422, 'INVALID_METADATA', 'Each set must contain root and items.');
            $root = ImageStorage::path($set['root'] ?? null);
            $items = $set['items'] ?? null;
            if (!is_array($items) || !array_is_list($items) || !$items) throw new ApiError(422, 'INVALID_METADATA', 'Items must be a nonempty array.');
            foreach ($items as $i => $entry) {
                if (!is_array($entry)) throw new ApiError(422, 'INVALID_METADATA', 'Each item must be an object.');
                if (isset($entry['variants'])) throw new ApiError(422, 'VARIANTS_NOT_SUPPORTED', 'Resizing is not available in version 1.');
                $path = ImageStorage::path($entry['path'] ?? '', true);
                $name = ImageStorage::name($entry['name'] ?? null);
                $target = $root . '/' . ($path === '' ? '' : $path . '/') . $name;
                if (isset($seen[$target])) throw new ApiError(409, 'DUPLICATE_PATH', 'The batch contains duplicate destination paths.');
                $seen[$target] = true;
                $error = $files['error'][$s][$i] ?? UPLOAD_ERR_NO_FILE;
                if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new ApiError(413, 'IMAGE_TOO_LARGE', 'An image exceeds the PHP upload limit.');
                $temp = $files['tmp_name'][$s][$i] ?? '';
                if ($error !== UPLOAD_ERR_OK || !is_string($temp) || !is_uploaded_file($temp)) throw new ApiError(422, 'UPLOAD_FAILED', "Missing or failed image at images[$s][$i].");
                $validator->validate($temp, $name);
                $uploads[] = ['source' => $temp, 'path' => $target];
                if (count($uploads) > $config['max_batch_images']) throw new ApiError(413, 'BATCH_TOO_LARGE', 'Too many images in one request.');
            }
        }
        $fileCount = 0;
        foreach (($files['error'] ?? []) as $group) {
            if (!is_array($group)) throw new ApiError(422, 'INVALID_FILES', 'Use images[set][item] file fields.');
            $fileCount += count($group);
        }
        if ($fileCount !== count($uploads)) throw new ApiError(422, 'INVALID_FILES', 'Every uploaded file must match a metadata item.');
        $images = $storage->locked(function () use ($storage, $uploads) {
            foreach ($uploads as $upload) if (file_exists($storage->resolve($upload['path']))) throw new ApiError(409, 'IMAGE_EXISTS', 'An image already exists at ' . $upload['path']);
            $saved = [];
            try { foreach ($uploads as $upload) $saved[] = $storage->save($upload['source'], $upload['path']); }
            catch (Throwable $error) { foreach ($saved as $image) $storage->delete($image['path']); throw $error; }
            return $saved;
        });
        respond(['images' => $images], 201);
    }
    if ($item !== null && in_array($method, ['GET', 'HEAD'], true)) {
        $file = $storage->existing($item);
        $info = $storage->info($item);
        header('Content-Type: ' . $info['mime']);
        header('Content-Length: ' . $info['size']);
        header('Cache-Control: no-cache');
        if ($method === 'GET') readfile($file);
        exit;
    }
    if ($item !== null && $method === 'PUT') {
        $source = fopen('php://input', 'rb');
        $temp = tmpfile();
        if (!$source || !$temp) throw new RuntimeException('Cannot read request body.');
        try {
            $bytes = stream_copy_to_stream($source, $temp, $config['max_image_bytes'] + 1);
            if ($bytes > $config['max_image_bytes']) throw new ApiError(413, 'IMAGE_TOO_LARGE', 'The image exceeds the configured size limit.');
            $tempPath = stream_get_meta_data($temp)['uri'];
            $image = $storage->locked(fn() => $storage->save($tempPath, $item, true));
            respond(['image' => $image]);
        } finally { fclose($source); fclose($temp); }
    }
    if ($item !== null && $method === 'PATCH') {
        $body = jsonBody();
        $root = ImageStorage::path($body['root'] ?? null);
        $path = ImageStorage::path($body['path'] ?? '', true);
        $name = ImageStorage::name($body['name'] ?? null);
        $destination = $root . '/' . ($path === '' ? '' : $path . '/') . $name;
        respond(['image' => $storage->locked(fn() => $storage->move($item, $destination))]);
    }
    if ($item !== null && $method === 'DELETE') {
        $storage->locked(fn() => $storage->delete($item));
        http_response_code(204); exit;
    }
    header('Allow: ' . ($collection ? 'GET, POST, OPTIONS' : 'GET, HEAD, PUT, PATCH, DELETE, OPTIONS'));
    throw new ApiError(405, 'METHOD_NOT_ALLOWED', 'This method is not supported for this route.');
} catch (ApiError $error) {
    respond(['error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]], $error->status);
} catch (Throwable $error) {
    error_log((string) $error);
    respond(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'The service could not complete the request.']], 500);
}
