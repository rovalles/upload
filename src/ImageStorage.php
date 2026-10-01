<?php
declare(strict_types=1);

final class ImageStorage
{
    private string $directory;
    public function __construct(string $directory, private string $baseUrl, private ImageValidator $validator)
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true)) throw new RuntimeException('Cannot create storage directory.');
        $this->directory = realpath($directory);
    }

    public static function path(mixed $value, bool $empty = false): string
    {
        if (!is_string($value)) throw new ApiError(422, 'INVALID_PATH', 'Paths must be strings.');
        $value = trim($value, '/');
        if ($value === '' && $empty) return '';
        if ($value === '' || strlen($value) > 1024) throw new ApiError(422, 'INVALID_PATH', 'A nonempty relative path is required.');
        foreach (explode('/', $value) as $part) {
            if (!preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._ -]*\z/D', $part) || strlen($part) > 255 || str_ends_with($part, '.') || str_ends_with($part, ' '))
                throw new ApiError(422, 'INVALID_PATH', 'Invalid path component. Dot paths, backslashes and special characters are not allowed.');
        }
        return $value;
    }

    public static function name(mixed $value): string
    {
        $name = self::path($value);
        if (!is_string($value) || $value !== $name || str_contains($name, '/')) throw new ApiError(422, 'INVALID_NAME', 'Name must be a filename, without folders.');
        return $name;
    }

    public function resolve(string $path): string
    {
        $path = self::path($path);
        $current = $this->directory;
        foreach (explode('/', $path) as $part) {
            $current .= '/' . $part;
            if (is_link($current)) throw new ApiError(422, 'INVALID_PATH', 'Symbolic links are not allowed.');
        }
        return $current;
    }

    public function existing(string $path): string
    {
        $file = $this->resolve($path);
        if (!is_file($file)) throw new ApiError(404, 'IMAGE_NOT_FOUND', 'The requested image does not exist.');
        return $file;
    }

    public function info(string $path): array
    {
        return ['path' => $path, 'url' => $this->baseUrl . '/api/images/' . implode('/', array_map('rawurlencode', explode('/', $path)))]
            + $this->validator->validate($this->existing($path), basename($path));
    }

    public function locked(callable $operation): mixed
    {
        $lock = fopen($this->directory . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock storage.');
        try { return $operation(); } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function save(string $source, string $path, bool $replace = false): array
    {
        $destination = $this->resolve($path);
        $this->validator->validate($source, basename($path));
        if ($replace) $this->existing($path);
        elseif (file_exists($destination)) throw new ApiError(409, 'IMAGE_EXISTS', 'An image already exists at this path.');
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0750, true)) throw new RuntimeException('Cannot create image folder.');
        $temp = tempnam($parent, '.upload-');
        try {
            if (!copy($source, $temp) || !chmod($temp, 0640) || !rename($temp, $destination)) throw new RuntimeException('Cannot save image.');
        } finally { if (is_file($temp)) unlink($temp); }
        return $this->info($path);
    }

    public function listing(string $folder): array
    {
        $directory = $this->resolve($folder);
        if (!is_dir($directory)) throw new ApiError(404, 'FOLDER_NOT_FOUND', 'The requested folder does not exist.');
        $images = [];
        foreach (scandir($directory) as $name) {
            if (str_starts_with($name, '.') || is_link($directory . '/' . $name) || !is_file($directory . '/' . $name)) continue;
            $images[] = $this->info($folder . '/' . $name);
        }
        return $images;
    }

    public function move(string $from, string $to): array
    {
        $source = $this->existing($from);
        $this->validator->validate($source, basename($to));
        if ($from === $to) return $this->info($from);
        $destination = $this->resolve($to);
        if (file_exists($destination)) throw new ApiError(409, 'IMAGE_EXISTS', 'The destination already exists.');
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0750, true)) throw new RuntimeException('Cannot create destination folder.');
        if (!rename($source, $destination)) throw new RuntimeException('Cannot move image.');
        return $this->info($to);
    }
    public function delete(string $path): void
    {
        if (!unlink($this->existing($path))) throw new RuntimeException('Cannot delete image.');
    }
}
