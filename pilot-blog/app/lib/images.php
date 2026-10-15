<?php
// Image uploads (SPEC §11.8): every image is decoded and re-encoded with GD, so nothing but pixels survives.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const UPLOAD_MAX_BYTES = 12 * 1024 * 1024;
const IMG_MAX_SIDE = 8000;
const IMG_MAX_PIXELS = 30000000;
const IMG_LONG_EDGE = 2000;
const IMG_OG_EDGE = 1200;

function upload_limit_bytes(): int
{
    $ini = function (string $k): int {
        $v = trim((string) ini_get($k));
        if ($v === '') {
            return PHP_INT_MAX;
        }
        $n = (int) $v;
        $u = strtolower(substr($v, -1));
        return match ($u) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    };
    return min(UPLOAD_MAX_BYTES, $ini('upload_max_filesize'), $ini('post_max_size'));
}

function uploads_index(): array
{
    $d = json_read(data_path('uploads', 'index.json'), ['items' => []]);
    return is_array($d) && is_array($d['items'] ?? null) ? $d : ['items' => []];
}

/** From a PHP upload ($_FILES['image']). Returns ['ok' => true, 'src', 'og', 'w', 'h']; throws ValidationError. */
function image_accept_upload(array $file, string $purpose): array
{
    if (!in_array($purpose, ['cover', 'body'], true)) {
        throw new ValidationError(['image' => '上传用途不对。']);
    }
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new ValidationError(['image' => '图片太大了，最大 ' . intdiv(upload_limit_bytes(), 1048576) . ' MB。']);
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new ValidationError(['image' => '图片没有传上来，再试一次。']);
    }
    return image_store_file((string) $file['tmp_name']);
}

function image_fail(string $msg): never
{
    throw new ValidationError(['image' => $msg]);
}

/** Validate, decode, re-encode and store one image file (also used by tests and the CLI). */
function image_store_file(string $path): array
{
    $size = @filesize($path);
    if ($size === false || $size < 1) {
        image_fail('图片是空的，换一张试试。');
    }
    if ($size > upload_limit_bytes()) {
        image_fail('图片太大了，最大 ' . intdiv(upload_limit_bytes(), 1048576) . ' MB。');
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true)) {
        image_fail('这张是 HEIC 格式。请在 iPhone 设置 → 相机 → 格式 里选「兼容性最佳」，或者截个图再传。');
    }
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        image_fail('只能上传 JPG、PNG、WebP 或 GIF 图片。');
    }
    $info = @getimagesize($path);
    if ($info === false || $info[0] < 1 || $info[1] < 1) {
        image_fail('这张图片打不开，换一张试试。');
    }
    if ($info[0] > IMG_MAX_SIDE || $info[1] > IMG_MAX_SIDE || $info[0] * $info[1] > IMG_MAX_PIXELS) {
        image_fail('图片尺寸太大了，长边请不要超过 8000 像素。');
    }
    @ini_set('memory_limit', '512M');
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        'image/gif' => @imagecreatefromgif($path),
    };
    if (!$img) {
        image_fail('这张图片打不开，换一张试试。');
    }
    if ($mime === 'image/jpeg') {
        $img = image_apply_exif($img, $path);
    }
    if (!imageistruecolor($img)) {
        $tc = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagealphablending($tc, false);
        imagesavealpha($tc, true);
        imagefill($tc, 0, 0, imagecolorallocatealpha($tc, 0, 0, 0, 127));
        imagecopy($tc, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
        imagedestroy($img);
        $img = $tc;
    }
    $main = image_fit($img, IMG_LONG_EDGE, false);
    $og = image_fit($img, IMG_OG_EDGE, true);
    imagedestroy($img);

    $ym = gmdate('Y/m');
    $name = bin2hex(random_bytes(8));
    $webp = function_exists('imagewebp');
    $ext = $webp ? 'webp' : 'jpg';
    $src = 'uploads/' . $ym . '/' . $name . '.' . $ext;
    $ogRel = 'uploads/' . $ym . '/' . ($webp ? $name : bin2hex(random_bytes(8))) . '.jpg';   // without WebP the main file is a .jpg too
    $mainBytes = image_encode($main, $ext, $webp ? 80 : 85);
    $ogBytes = image_encode($og, 'jpg', 82);
    $w = imagesx($main);
    $h = imagesy($main);
    imagedestroy($main);
    imagedestroy($og);

    foreach ([$src => $mainBytes, $ogRel => $ogBytes] as $rel => $bytes) {
        file_write_atomic(DATA_DIR . '/' . $rel, $bytes, 0600);
        file_write_atomic(PUBLIC_DIR . '/' . $rel, $bytes, 0644);
    }
    json_update(data_path('uploads', 'index.json'), function ($d) use ($src, $ogRel, $w, $h, $mainBytes) {
        $d = is_array($d) && is_array($d['items'] ?? null) ? $d : ['items' => []];
        $d['items'][$src] = ['w' => $w, 'h' => $h, 'og' => $ogRel, 'bytes' => strlen($mainBytes), 'created_at' => now_iso()];
        return $d;
    }, ['items' => []]);
    return ['ok' => true, 'src' => $src, 'og' => $ogRel, 'w' => $w, 'h' => $h];
}

function image_apply_exif(GdImage $img, string $path): GdImage
{
    if (!function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data($path);
    $o = (int) ($exif['Orientation'] ?? 1);
    if (in_array($o, [2, 4, 5, 7], true)) {
        imageflip($img, IMG_FLIP_HORIZONTAL);
    }
    $angle = match ($o) { 3, 4 => 180, 5, 6 => 270, 7, 8 => 90, default => 0 };
    if ($angle !== 0) {
        $r = imagerotate($img, $angle, 0);
        if ($r) {
            imagedestroy($img);
            $img = $r;
        }
    }
    return $img;
}

/** Copy scaled to fit $edge (long side); $flatten paints transparent pixels on the site canvas #0A1733. */
function image_fit(GdImage $img, int $edge, bool $flatten): GdImage
{
    $w = imagesx($img);
    $h = imagesy($img);
    $s = min(1, $edge / max($w, $h));
    $nw = max(1, (int) round($w * $s));
    $nh = max(1, (int) round($h * $s));
    $out = imagecreatetruecolor($nw, $nh);
    if ($flatten) {
        imagefill($out, 0, 0, imagecolorallocate($out, 0x0A, 0x17, 0x33));
        imagealphablending($out, true);
    } else {
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    }
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $out;
}

function image_encode(GdImage $img, string $ext, int $q): string
{
    ob_start();
    $ok = $ext === 'webp' ? imagewebp($img, null, $q) : imagejpeg($img, null, $q);
    $bytes = (string) ob_get_clean();
    if (!$ok || $bytes === '') {
        image_fail('图片处理失败，换一张试试。');
    }
    return $bytes;
}

/** The set of upload paths (src and og) that live content still references, as rel => true. */
function uploads_referenced(): array
{
    $refs = [];
    $add = function ($rel) use (&$refs): void {
        if (is_string($rel) && preg_match('#^uploads/\d{4}/\d{2}/[0-9a-f]{16}\.(?:webp|jpg)$#', $rel)) {
            $refs[$rel] = true;
        }
    };
    $scan = function (string $text) use ($add): void {
        if (preg_match_all('#uploads/\d{4}/\d{2}/[0-9a-f]{16}\.(?:webp|jpg)#', $text, $m)) {
            foreach ($m[0] as $u) {
                $add($u);
            }
        }
    };
    // only published or teaser posts contribute: their live cover (src + og) and the images in the live body
    foreach (posts_all() as $p) {
        $live = $p['live'] ?? null;
        if (!is_array($live)) {
            continue;
        }
        $cover = $live['cover'] ?? null;
        if (is_array($cover)) {
            $add($cover['src'] ?? '');
            $add($cover['og'] ?? '');
        }
        $scan((string) ($live['body'] ?? ''));
    }
    // and anything the live site or shelf fields point at
    foreach ([site_live(), shelf_live()] as $doc) {
        $scan((string) json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    return $refs;
}

/**
 * Restore the public copy of every upload that live content still references and is missing from
 * public/uploads/ (self-heal after a deploy wipe). A master that nothing live points at is left alone,
 * so a photo the owner takes down by deleting both copies stays down and is not quietly re-published.
 * Returns how many files were copied.
 */
function uploads_sync_public(): int
{
    $n = 0;
    $base = DATA_DIR . '/uploads';
    $refs = uploads_referenced();
    foreach ($refs as $rel => $_) {
        $src = $base . '/' . substr($rel, strlen('uploads/'));
        $dest = PUBLIC_DIR . '/' . $rel;
        if (is_file($src) && !is_file($dest)) {
            file_write_atomic($dest, (string) file_get_contents($src), 0644);
            $n++;
        }
    }
    $ht = PUBLIC_DIR . '/uploads/.htaccess';
    if (!is_file($ht) && is_file(APP_DIR . '/templates/uploads.htaccess')) {
        file_write_atomic($ht, (string) file_get_contents(APP_DIR . '/templates/uploads.htaccess'), 0644);
    }
    return $n;
}
