<?php
/**
 * Category image handling and crop management shared by admin pages.
 *
 * The upload itself is validated by sh_upload_image() (real MIME sniffing,
 * generated filename, allowlisted extension). This file owns the rules the
 * category editor depends on:
 *
 *   1. a file is only ever deleted once the row no longer points at it;
 *   2. a replacement never removes the previous image before the new one has
 *      been uploaded, validated and stored successfully;
 *   3. original full-resolution uploads are preserved so the administrator can
 *      re-crop or reposition at any time without quality degradation;
 *   4. crop coordinates, zoom, and aspect ratio are persisted in `categories.image_crop`.
 */
declare(strict_types=1);

/**
 * Self-healing migration for category image storage and card size.
 *
 * Runs once per request at most, and only adds a column that is really missing,
 * so it is safe on a live store and on every supported MySQL/MariaDB version.
 */
function sh_category_schema_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    try {
        $pdo = sh_db();
        if ($pdo->inTransaction()) { return; }
        $cols = [
            'image_orig' => 'VARCHAR(190) DEFAULT NULL',
            'image_crop' => 'TEXT DEFAULT NULL',
            // Storefront card size chosen per category. Existing rows take the
            // column default, so every pre-existing category becomes "medium"
            // exactly like a category created after this release.
            'card_size'  => "ENUM('small','medium','large') NOT NULL DEFAULT 'medium'",
        ];
        foreach ($cols as $col => $def) {
            $n = (int)sh_val(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                ['categories', $col],
                0
            );
            if ($n === 0) {
                $pdo->exec("ALTER TABLE categories ADD COLUMN `$col` $def");
            }
        }
    } catch (Throwable $e) {
        if (function_exists('sh_log_exception')) {
            sh_log_exception($e, 'category-schema');
        }
    }
}

/**
 * Delete one stored category image.
 *
 * basename() is what keeps this inside uploads/categories: the value comes from
 * the database, and a stored "../../config/database.php" must not be able to
 * reach anything outside the category folder.
 */
function sh_category_delete_file(?string $image): bool
{
    $name = basename(trim((string)$image));
    if ($name === '' || $name === '.' || $name === '..') { return false; }
    $path = SH_UPLOAD_DIR . '/categories/' . $name;
    if (!is_file($path)) { return false; }
    if (!unlink($path)) {
        sh_log_line('category', 'Could not delete category image ' . $name);
        return false;
    }
    return true;
}

/**
 * Public URL for the original uncropped category image, falling back to current image.
 */
function sh_category_orig_image(?string $orig, ?string $current): string
{
    $name = basename(trim((string)$orig));
    if ($name !== '' && $name !== '.' && $name !== '..' && is_file(SH_UPLOAD_DIR . '/categories/' . $name)) {
        return sh_url('uploads/categories/' . rawurlencode($name));
    }
    return sh_category_image($current);
}

/**
 * Perform server-side image cropping and resizing using GD.
 *
 * Preserves transparency for PNG/WebP/GIF, resamples with high quality,
 * and handles boundary constraints cleanly.
 */
function sh_image_crop_save(
    string $sourcePath,
    string $destPath,
    int $srcX,
    int $srcY,
    int $srcW,
    int $srcH,
    int $targetW = 800,
    int $targetH = 600
): bool {
    if (!is_file($sourcePath) || !is_readable($sourcePath)) {
        return false;
    }

    set_error_handler(static fn(): bool => true);
    try {
        $info = getimagesize($sourcePath);
    } finally {
        restore_error_handler();
    }
    if ($info === false) { return false; }

    $origW = (int)$info[0];
    $origH = (int)$info[1];
    $mime  = (string)($info['mime'] ?? '');

    // Constrain crop source box within the real original image bounds
    $srcX = max(0, min($origW - 1, $srcX));
    $srcY = max(0, min($origH - 1, $srcY));
    $srcW = max(1, min($origW - $srcX, $srcW));
    $srcH = max(1, min($origH - $srcY, $srcH));

    // Calculate proportional target dimensions if not fixed
    if ($targetW <= 0 && $targetH <= 0) {
        $targetW = min(800, max(200, $srcW));
        $targetH = max(1, (int)round($targetW * ($srcH / $srcW)));
    } elseif ($targetW <= 0) {
        $targetW = max(1, (int)round($targetH * ($srcW / $srcH)));
    } elseif ($targetH <= 0) {
        $targetH = max(1, (int)round($targetW * ($srcH / $srcW)));
    }

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($sourcePath),
        'image/png'  => @imagecreatefrompng($sourcePath),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
        'image/gif'  => @imagecreatefromgif($sourcePath),
        default      => false,
    };
    if ($src === false) { return false; }

    $dst = imagecreatetruecolor($targetW, $targetH);
    if ($dst === false) {
        imagedestroy($src);
        return false;
    }

    // Preserve alpha channel for transparent PNG, WebP and GIF
    if ($mime === 'image/png' || $mime === 'image/webp' || $mime === 'image/gif') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        if ($transparent !== false) {
            imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $transparent);
        }
    } else {
        // High quality white background for JPEGs
        $white = imagecolorallocate($dst, 255, 255, 255);
        if ($white !== false) {
            imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $white);
        }
    }

    imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $targetW, $targetH, $srcW, $srcH);
    imagedestroy($src);

    $ok = match ($mime) {
        'image/jpeg' => @imagejpeg($dst, $destPath, 92),
        'image/png'  => @imagepng($dst, $destPath, 8),
        'image/webp' => function_exists('imagewebp') ? @imagewebp($dst, $destPath, 90) : @imagejpeg($dst, $destPath, 92),
        'image/gif'  => @imagegif($dst, $destPath),
        default      => @imagejpeg($dst, $destPath, 92),
    };
    imagedestroy($dst);

    if ($ok) {
        @chmod($destPath, 0644);
    }
    return (bool)$ok;
}

/**
 * Decode and securely save a client-side cropped base64 data URL.
 */
function sh_image_save_data_url(string $dataUrl, string $subdir = 'categories'): ?string
{
    if (!preg_match('~^data:image/(png|jpeg|jpg|webp);base64,([A-Za-z0-9+/=]+)$~', $dataUrl, $m)) {
        return null;
    }
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $raw = base64_decode($m[2], true);
    if ($raw === false || strlen($raw) < 100) { return null; }

    set_error_handler(static fn(): bool => true);
    try {
        $info = getimagesizefromstring($raw);
    } finally {
        restore_error_handler();
    }
    if ($info === false) { return null; }

    $dir = SH_UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) { return null; }

    $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $path = $dir . '/' . $name;
    if (file_put_contents($path, $raw) === false) { return null; }
    @chmod($path, 0644);
    return $name;
}

/**
 * Crop an existing category file according to crop data coordinates.
 *
 * @param array{x?:int|float,y?:int|float,width?:int|float,height?:int|float,aspect?:string,zoom?:float} $cropData
 */
function sh_image_crop_from_data(string $sourceFilename, array $cropData, ?string $fallbackDataUrl = null): ?string
{
    $name = basename(trim($sourceFilename));
    if ($name === '' || $name === '.' || $name === '..') { return null; }
    $srcPath = SH_UPLOAD_DIR . '/categories/' . $name;
    if (!is_file($srcPath)) {
        if ($fallbackDataUrl !== null && $fallbackDataUrl !== '') {
            return sh_image_save_data_url($fallbackDataUrl, 'categories');
        }
        return null;
    }

    $x = (int)round((float)($cropData['x'] ?? 0));
    $y = (int)round((float)($cropData['y'] ?? 0));
    $w = (int)round((float)($cropData['width'] ?? 0));
    $h = (int)round((float)($cropData['height'] ?? 0));

    if ($w <= 0 || $h <= 0) {
        if ($fallbackDataUrl !== null && $fallbackDataUrl !== '') {
            return sh_image_save_data_url($fallbackDataUrl, 'categories');
        }
        return null;
    }

    // Determine target dimensions based on aspect ratio
    $aspect = (string)($cropData['aspect'] ?? '4:3');
    [$targetW, $targetH] = match ($aspect) {
        '1:1'   => [800, 800],
        '16:9'  => [960, 540],
        'free'  => [min(800, max(200, $w)), max(1, (int)round(min(800, max(200, $w)) * ($h / $w)))],
        default => [800, 600], // 4:3 storefront standard
    };

    $ext = pathinfo($name, PATHINFO_EXTENSION) ?: 'jpg';
    $croppedName = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destPath = SH_UPLOAD_DIR . '/categories/' . $croppedName;

    $success = sh_image_crop_save($srcPath, $destPath, $x, $y, $w, $h, $targetW, $targetH);
    if ($success) {
        return $croppedName;
    }

    // If GD failed or extension not supported, try client canvas fallback
    if ($fallbackDataUrl !== null && $fallbackDataUrl !== '') {
        $savedFallback = sh_image_save_data_url($fallbackDataUrl, 'categories');
        if ($savedFallback !== null) { return $savedFallback; }
    }

    return null;
}

/**
 * Resolve one submitted image field, accounting for crop data and original uploads.
 *
 * Returns:
 *   ['action' => 'keep'|'set'|'remove', 'file' => ?string, 'orig_file' => ?string,
 *    'crop_data' => ?string, 'error' => ?string, 'uploaded' => ?string]
 *
 * @param array<string,mixed> $row     the row being edited (may be null)
 * @param array<string,mixed> $fields  usually $_POST
 * @param array<string,mixed> $files   usually $_FILES
 */
function sh_category_resolve_image(?array $row, array $fields, array $files): array
{
    sh_category_schema_ensure();
    $current = $row['image'] ?? null;
    $currentOrig = $row['image_orig'] ?? $current;
    $currentCrop = $row['image_crop'] ?? null;

    $out = [
        'action'    => 'keep',
        'file'      => $current,
        'orig_file' => $currentOrig,
        'crop_data' => $currentCrop,
        'error'     => null,
        'uploaded'  => null,
    ];

    $upload = $files['image'] ?? null;
    $hasUpload = is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    $cropRaw = trim((string)($fields['crop_data'] ?? ''));
    $cropData = $cropRaw !== '' ? json_decode($cropRaw, true) : null;
    $fallbackDataUrl = !empty($fields['cropped_image_data']) ? (string)$fields['cropped_image_data'] : null;

    // Case 1: Fresh upload
    if ($hasUpload) {
        $up = sh_upload_image($upload, 'categories', 0, 6000);
        if (empty($up['ok'])) {
            $out['error'] = (string)($up['error'] ?? 'The image could not be uploaded.');
            return $out;
        }
        $origFile = (string)$up['file'];

        // If custom crop data was provided, generate the cropped version
        if (is_array($cropData) && !empty($cropData['width']) && !empty($cropData['height'])) {
            $croppedFile = sh_image_crop_from_data($origFile, $cropData, $fallbackDataUrl);
            if ($croppedFile !== null) {
                $out['action']    = 'set';
                $out['file']      = $croppedFile;
                $out['orig_file'] = $origFile;
                $out['crop_data'] = $cropRaw;
                $out['uploaded']  = $croppedFile;
                return $out;
            }
        }

        // Without crop (or if crop failed), the uploaded file is used directly
        $out['action']    = 'set';
        $out['file']      = $origFile;
        $out['orig_file'] = $origFile;
        $out['crop_data'] = is_array($cropData) ? $cropRaw : null;
        $out['uploaded']  = $origFile;
        return $out;
    }

    // Case 2: Explicit removal
    if (!empty($fields['remove_image'])) {
        $out['action']    = 'remove';
        $out['file']      = null;
        $out['orig_file'] = null;
        $out['crop_data'] = null;
        return $out;
    }

    // Case 3: Re-crop existing image without a new upload
    if ($current !== null && is_array($cropData) && !empty($cropData['width']) && !empty($cropData['height'])) {
        if ($cropRaw !== (string)$currentCrop || !empty($fields['force_recrop'])) {
            $sourceFile = $currentOrig ?: $current;
            $croppedFile = sh_image_crop_from_data($sourceFile, $cropData, $fallbackDataUrl);
            if ($croppedFile !== null) {
                $out['action']    = 'set';
                $out['file']      = $croppedFile;
                $out['orig_file'] = $sourceFile;
                $out['crop_data'] = $cropRaw;
                $out['uploaded']  = $croppedFile;
                return $out;
            }
        }
    }

    return $out;
}
