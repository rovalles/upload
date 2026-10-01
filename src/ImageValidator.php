<?php
declare(strict_types=1);

final class ImageValidator
{
    public function __construct(private int $maxBytes) {}

    public function validate(string $file, string $name): array
    {
        $size = filesize($file);
        if ($size === false || $size === 0) throw new ApiError(422, 'INVALID_IMAGE', 'The image is empty.');
        if ($size > $this->maxBytes) throw new ApiError(413, 'IMAGE_TOO_LARGE', 'The image exceeds the configured size limit.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
        $extensions = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp'], 'image/gif' => ['gif']];
        $dimensions = @getimagesize($file);
        if (!isset($extensions[$mime]) || !$dimensions || ($dimensions['mime'] ?? '') !== $mime)
            throw new ApiError(422, 'INVALID_IMAGE', 'Only valid JPEG, PNG, WebP and GIF images are supported.');
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions[$mime], true))
            throw new ApiError(422, 'EXTENSION_MISMATCH', 'The filename extension must match the image type.');
        return ['mime' => $mime, 'size' => $size, 'width' => $dimensions[0], 'height' => $dimensions[1]];
    }
}
