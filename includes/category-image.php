<?php
/**
 * Category image handling shared by the admin pages.
 *
 * The upload itself is validated by sh_upload_image() (real MIME sniffing,
 * generated filename, allowlisted extension). This file owns the two rules the
 * category editor depends on:
 *
 *   1. a file is only ever deleted once the row no longer points at it, and
 *   2. a replacement never removes the previous image before the new one has
 *      been uploaded, validated and stored successfully.
 */
declare(strict_types=1);

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
 * Resolve one submitted image field and report what should be stored.
 *
 * Returns:
 *   ['action' => 'keep'|'set'|'remove', 'file' => ?string, 'error' => ?string,
 *    'uploaded' => ?string]  (uploaded = the new file, so the caller can remove
 *                            it again if a later validation step fails)
 *
 * The existing image is only replaced after the new file passed validation, and
 * only ever deleted by the caller once the database row has been written.
 *
 * @param array<string,mixed> $row        the row being edited (may be null)
 * @param array<string,mixed> $fields    usually $_POST
 * @param array<string,mixed> $files     usually $_FILES
 */
function sh_category_resolve_image(?array $row, array $fields, array $files): array
{
    $current = $row['image'] ?? null;
    $out = ['action' => 'keep', 'file' => $current, 'error' => null, 'uploaded' => null];

    // "Remove the current image" is an explicit, separate checkbox. It only
    // applies when no replacement is being uploaded in the same request.
    $upload = $files['image'] ?? null;
    $hasUpload = is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($hasUpload) {
        $up = sh_upload_image($upload, 'categories', 0, 4000);
        if (empty($up['ok'])) {
            $out['error'] = (string)($up['error'] ?? 'The image could not be uploaded.');
            return $out;
        }
        $out['action']   = 'set';
        $out['file']     = (string)$up['file'];
        $out['uploaded'] = (string)$up['file'];
        return $out;
    }

    if (!empty($fields['remove_image'])) {
        $out['action'] = 'remove';
        $out['file']   = null;
    }
    return $out;
}
