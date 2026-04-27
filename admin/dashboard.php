<?php
include '../db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    die("Access Denied");
}

// =========================================================================
// Resolve current admin (works whether login stored user_id, username, or both)
// =========================================================================
$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id && isset($_SESSION['username'])) {
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND role = 'Admin' LIMIT 1");
    $stmt->execute([$_SESSION['username']]);
    $current_user_id = $stmt->fetchColumn() ?: null;
    if ($current_user_id) {
        $_SESSION['user_id'] = (int)$current_user_id;
    }
}
if (!$current_user_id) {
    die("Session expired. Please log in again.");
}

// Load this admin's profile (creates one on the fly for legacy admins
// who existed before the migration was run).
$stmt = $pdo->prepare("
    SELECT ap.admin_id, ap.user_id, ap.full_name, ap.is_super_admin,
           ap.must_change_password, u.username
    FROM users u
    LEFT JOIN admin_profiles ap ON ap.user_id = u.user_id
    WHERE u.user_id = ? AND u.role = 'Admin' LIMIT 1
");
$stmt->execute([$current_user_id]);
$current_admin = $stmt->fetch(PDO::FETCH_ASSOC);

if ($current_admin && $current_admin['admin_id'] === null) {
    // Legacy admin row without profile — auto-promote to super admin
    // if they're admin@gmail.com, otherwise create a regular profile.
    $is_super = ($current_admin['username'] === 'admin@gmail.com') ? 1 : 0;
    $pdo->prepare("
        INSERT INTO admin_profiles (user_id, full_name, is_super_admin, must_change_password)
        VALUES (?, ?, ?, 0)
    ")->execute([$current_user_id, 'Administrator', $is_super]);
    $stmt->execute([$current_user_id]);
    $current_admin = $stmt->fetch(PDO::FETCH_ASSOC);
}

$is_super_admin = !empty($current_admin['is_super_admin']);

// =========================================================================
// Action handlers
// =========================================================================
$action_msg = '';   // success / info banner
$action_err = '';   // error banner

// --- Reservation approve / reject (existing) -----------------------------
if (isset($_GET['approve'])) {
    $pdo->prepare("UPDATE reservations SET status='Confirmed' WHERE reservation_id=?")
        ->execute([$_GET['approve']]);
    header("Location: dashboard.php");
    exit;
}
if (isset($_GET['reject'])) {
    $pdo->prepare("UPDATE reservations SET status='Cancelled' WHERE reservation_id=?")
        ->execute([$_GET['reject']]);
    header("Location: dashboard.php");
    exit;
}

$is_post = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');

// --- Add a new admin (any admin can add) ---------------------------------
if ($is_post && ($_POST['action'] ?? '') === 'add_admin') {
    $new_email = trim($_POST['email'] ?? '');
    $new_name  = trim($_POST['full_name'] ?? '');
    $default_password = 'ChangeMe@123';

    if ($new_email === '' || $new_name === '') {
        $action_err = "Full name and email are required.";
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $action_err = "Please provide a valid email address.";
    } else {
        // Reject if username already exists in users table
        $check = $pdo->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
        $check->execute([$new_email]);
        if ($check->fetchColumn()) {
            $action_err = "An account with that email already exists.";
        } else {
            try {
                $pdo->beginTransaction();
                $hash = password_hash($default_password, PASSWORD_DEFAULT);

                $pdo->prepare("
                    INSERT INTO users (username, password_hash, role, is_active, customer_id)
                    VALUES (?, ?, 'Admin', 1, NULL)
                ")->execute([$new_email, $hash]);
                $new_uid = (int)$pdo->lastInsertId();

                $pdo->prepare("
                    INSERT INTO admin_profiles
                        (user_id, full_name, is_super_admin, created_by_admin_id, must_change_password)
                    VALUES (?, ?, 0, ?, 1)
                ")->execute([$new_uid, $new_name, $current_admin['admin_id']]);

                $pdo->commit();
                $action_msg = "Admin '{$new_name}' added. "
                            . "Default password: <strong>{$default_password}</strong> "
                            . "— share securely; the new admin will be prompted to change it.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $action_err = "Could not add admin: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// --- Delete an admin (super admin only, cannot delete self or other supers)
if ($is_post && ($_POST['action'] ?? '') === 'delete_admin') {
    $target_id = (int)($_POST['admin_id'] ?? 0);

    if (!$is_super_admin) {
        $action_err = "Only the main administrator can delete admin accounts.";
    } elseif ($target_id === (int)$current_admin['admin_id']) {
        $action_err = "You cannot delete your own account.";
    } else {
        $stmt = $pdo->prepare("SELECT user_id, full_name, is_super_admin FROM admin_profiles WHERE admin_id = ?");
        $stmt->execute([$target_id]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            $action_err = "Admin not found.";
        } elseif ((int)$target['is_super_admin'] === 1) {
            $action_err = "The main administrator cannot be deleted.";
        } else {
            // Cascade through users → admin_profiles via FK ON DELETE CASCADE.
            $pdo->prepare("DELETE FROM users WHERE user_id = ?")
                ->execute([$target['user_id']]);
            $action_msg = "Admin '" . htmlspecialchars($target['full_name']) . "' has been removed.";
        }
    }
}

// --- Change own password (any admin) -------------------------------------
if ($is_post && ($_POST['action'] ?? '') === 'change_password') {
    $current_pw = $_POST['current_password'] ?? '';
    $new_pw     = $_POST['new_password'] ?? '';
    $confirm_pw = $_POST['confirm_password'] ?? '';

    if ($current_pw === '' || $new_pw === '' || $confirm_pw === '') {
        $action_err = "All password fields are required.";
    } elseif (strlen($new_pw) < 8) {
        $action_err = "New password must be at least 8 characters.";
    } elseif ($new_pw !== $confirm_pw) {
        $action_err = "New password and confirmation do not match.";
    } else {
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->execute([$current_user_id]);
        $stored = $stmt->fetchColumn();

        if (!$stored || !password_verify($current_pw, $stored)) {
            $action_err = "Current password is incorrect.";
        } else {
            $new_hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
                ->execute([$new_hash, $current_user_id]);
            $pdo->prepare("
                UPDATE admin_profiles
                SET must_change_password = 0, last_password_change = NOW()
                WHERE admin_id = ?
            ")->execute([$current_admin['admin_id']]);
            $action_msg = "Password updated successfully.";
        }
    }
}

// =========================================================================
// BRANCHES MANAGEMENT — POST handlers
// =========================================================================
// Upload directory (relative to /admin/) — admin writes here, customer reads
// from "/assets/branches/".
$BRANCH_UPLOAD_DIR_FS  = __DIR__ . '/../assets/branches/';      // physical path
$BRANCH_UPLOAD_DIR_WEB = 'assets/branches/';                    // value stored in DB
if (!is_dir($BRANCH_UPLOAD_DIR_FS)) {
    @mkdir($BRANCH_UPLOAD_DIR_FS, 0775, true);
}

/* ─── Upload limits ─────────────────────────────────────────────────────────
 * Max upload size is intentionally generous (25 MB per image). Anything
 * larger than the resize threshold is shrunk + re-encoded server-side, so
 * even 25 MB phone photos end up as ~150-400 KB on disk and load instantly
 * for customers. Truly unlimited uploads are not safe — they enable cheap
 * disk-fill DoS — but 25 MB comfortably covers high-resolution camera output.
 *
 * NOTE: PHP's own `upload_max_filesize` and `post_max_size` ini directives
 * also apply. The deployment notes describe how to raise them server-wide
 * (typical recommendation: 30M each, slightly above MAX_UPLOAD_BYTES).
 * ------------------------------------------------------------------------- */
const MAX_UPLOAD_BYTES   = 25 * 1024 * 1024;   // hard ceiling per file
const MAX_GALLERY_IMAGES = 9;                  // per-branch gallery cap
const RESIZE_LONG_EDGE   = 1920;               // optimize >1920 px to 1920 px
const JPEG_QUALITY       = 85;                 // 85 = good quality, ~6× smaller

// Helper: physically delete a file referenced from the DB.
$deleteBranchFile = function ($webPath) {
    if (!$webPath) return;
    // Don't delete the shared default placeholder
    if (strpos($webPath, 'assets/default') !== false) return;
    // Only delete files we ourselves manage (assets/branches/...)
    if (strpos($webPath, 'assets/branches/') !== 0) return;
    $fs = __DIR__ . '/../' . $webPath;
    if (is_file($fs)) @unlink($fs);
};

/**
 * Validate, optimize, and persist an uploaded image.
 *
 * Performs:
 *   1. MIME + extension whitelist check
 *   2. Size sanity check (<= MAX_UPLOAD_BYTES)
 *   3. EXIF orientation auto-rotate (JPEG only)
 *   4. Proportional downscale if either dimension > RESIZE_LONG_EDGE
 *   5. Re-encode (strips EXIF, predictable file size)
 *
 * Returns ['ok' => true, 'web_path' => '...', 'fs_path' => '...']
 * or      ['ok' => false, 'error' => '...'].
 */
$processBranchImage = function (array $file, int $branchId) use (
    $BRANCH_UPLOAD_DIR_FS, $BRANCH_UPLOAD_DIR_WEB
) {
    // 1. Catch upload errors first
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' =>
            'File exceeds the server upload limit. Increase upload_max_filesize / post_max_size in php.ini.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed (error code ' . (int)$file['error'] . ').'];
    }

    // 2. Hard ceiling — 25 MB
    if (($file['size'] ?? 0) > MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'error' => 'Image is too large (max '
            . (int)(MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB per file).'];
    }

    // 3. Type whitelist (extension AND MIME must agree)
    $allowed = [
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',  'webp' => 'image/webp',
        'gif'  => 'image/gif',
    ];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, WEBP, and GIF images are allowed.'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed, true) || $mime !== $allowed[$ext]) {
        return ['ok' => false, 'error' => 'File contents do not match the image extension.'];
    }

    // 4. Read the image with GD (degrade gracefully if GD is unavailable)
    $newName = sprintf('branch-%d-%s.%s', $branchId, bin2hex(random_bytes(6)), $ext);
    $destFs  = $BRANCH_UPLOAD_DIR_FS  . $newName;
    $destWeb = $BRANCH_UPLOAD_DIR_WEB . $newName;

    if (!extension_loaded('gd')) {
        // GD unavailable: just move the file as-is (still validated above).
        if (!@move_uploaded_file($file['tmp_name'], $destFs)) {
            return ['ok' => false, 'error' => 'Could not save uploaded file. Check folder permissions.'];
        }
        return ['ok' => true, 'web_path' => $destWeb, 'fs_path' => $destFs];
    }

    // Load source image
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($file['tmp_name']); break;
        case 'image/png':  $src = @imagecreatefrompng ($file['tmp_name']); break;
        case 'image/webp': $src = @imagecreatefromwebp($file['tmp_name']); break;
        case 'image/gif':  $src = @imagecreatefromgif ($file['tmp_name']); break;
        default:           $src = false;
    }
    if (!$src) {
        return ['ok' => false, 'error' => 'Could not read the uploaded image — the file may be corrupt.'];
    }

    // EXIF auto-rotate for JPEGs (so portrait phone photos display upright)
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        if (!empty($exif['Orientation'])) {
            switch ((int)$exif['Orientation']) {
                case 3: $src = imagerotate($src, 180, 0); break;
                case 6: $src = imagerotate($src, -90, 0); break;
                case 8: $src = imagerotate($src,  90, 0); break;
            }
        }
    }

    $w = imagesx($src);
    $h = imagesy($src);

    // Proportional downscale if larger than max edge
    if ($w > RESIZE_LONG_EDGE || $h > RESIZE_LONG_EDGE) {
        $ratio = ($w >= $h) ? RESIZE_LONG_EDGE / $w : RESIZE_LONG_EDGE / $h;
        $newW = (int)round($w * $ratio);
        $newH = (int)round($h * $ratio);
        $dst  = imagecreatetruecolor($newW, $newH);
        // Preserve transparency for PNG / GIF
        if ($mime === 'image/png' || $mime === 'image/gif' || $mime === 'image/webp') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }

    // Re-encode (strips EXIF, predictable size)
    $saveOk = false;
    switch ($mime) {
        case 'image/jpeg': $saveOk = imagejpeg($src, $destFs, JPEG_QUALITY); break;
        case 'image/png':  $saveOk = imagepng ($src, $destFs, 6);             break;
        case 'image/webp': $saveOk = imagewebp($src, $destFs, JPEG_QUALITY);  break;
        case 'image/gif':  $saveOk = imagegif ($src, $destFs);                break;
    }
    imagedestroy($src);

    if (!$saveOk) {
        return ['ok' => false, 'error' => 'Could not save the optimized image. Check folder permissions.'];
    }
    return ['ok' => true, 'web_path' => $destWeb, 'fs_path' => $destFs];
};

// --- Toggle a single branch's availability -------------------------------
if ($is_post && ($_POST['action'] ?? '') === 'toggle_branch_availability') {
    $bid = (int)($_POST['branch_id'] ?? 0);
    $new = ((int)($_POST['new_state'] ?? 0) === 1) ? 1 : 0;
    $reason = trim($_POST['unavailable_reason'] ?? '');

    if ($bid <= 0) {
        $action_err = "Invalid branch.";
    } else {
        // Confirm branch exists
        $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
        $stmt->execute([$bid]);
        $bname = $stmt->fetchColumn();
        if (!$bname) {
            $action_err = "Branch not found.";
        } else {
            $pdo->prepare("
                INSERT INTO branch_status (branch_id, is_available, unavailable_reason, updated_by_admin_id)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    is_available        = VALUES(is_available),
                    unavailable_reason  = VALUES(unavailable_reason),
                    updated_by_admin_id = VALUES(updated_by_admin_id)
            ")->execute([$bid, $new, $new === 0 ? ($reason ?: null) : null, $current_admin['admin_id']]);

            $action_msg = "<strong>" . htmlspecialchars($bname) . "</strong> is now "
                        . ($new === 1 ? "<span style='color:#155724;'>available</span>"
                                      : "<span style='color:#721c24;'>unavailable</span>")
                        . " for booking.";
        }
    }
}

// --- Toggle GLOBAL maintenance for ALL branches --------------------------
if ($is_post && ($_POST['action'] ?? '') === 'set_global_maintenance') {
    $on = ((int)($_POST['state'] ?? 0) === 1) ? 1 : 0;
    $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, updated_by_admin_id)
        VALUES ('all_branches_maintenance', ?, ?)
        ON DUPLICATE KEY UPDATE
            setting_value       = VALUES(setting_value),
            updated_by_admin_id = VALUES(updated_by_admin_id)
    ")->execute([(string)$on, $current_admin['admin_id']]);

    $action_msg = $on === 1
        ? "All branches placed under maintenance &mdash; online bookings are now paused."
        : "Maintenance lifted &mdash; bookings are open again on every available branch.";
}

// --- Upload / replace a branch image -------------------------------------
// --- Upload / replace a branch's PRIMARY (cover) image -------------------
if ($is_post && ($_POST['action'] ?? '') === 'upload_branch_image') {
    $bid = (int)($_POST['branch_id'] ?? 0);

    if ($bid <= 0) {
        $action_err = "Invalid branch.";
    } elseif (!isset($_FILES['branch_image']) || $_FILES['branch_image']['error'] === UPLOAD_ERR_NO_FILE) {
        $action_err = "Please choose an image file to upload.";
    } else {
        // Confirm branch exists
        $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
        $stmt->execute([$bid]);
        $bname = $stmt->fetchColumn();
        if (!$bname) {
            $action_err = "Branch not found.";
        } else {
            $result = $processBranchImage($_FILES['branch_image'], $bid);
            if (!$result['ok']) {
                $action_err = $result['error'];
            } else {
                try {
                    $pdo->beginTransaction();

                    // Find the current primary image so we can clean it up
                    $cur = $pdo->prepare("
                        SELECT image_id, image_path
                        FROM   branch_images
                        WHERE  branch_id = ? AND is_primary = 1
                        LIMIT  1
                    ");
                    $cur->execute([$bid]);
                    $existing = $cur->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        $pdo->prepare("
                            UPDATE branch_images
                            SET    image_path           = ?,
                                   uploaded_at          = NOW(),
                                   uploaded_by_admin_id = ?
                            WHERE  image_id = ?
                        ")->execute([$result['web_path'], $current_admin['admin_id'], $existing['image_id']]);
                        $deleteBranchFile($existing['image_path']);
                    } else {
                        $pdo->prepare("
                            INSERT INTO branch_images
                                (branch_id, image_path, is_primary, sort_order, uploaded_by_admin_id)
                            VALUES (?, ?, 1, 0, ?)
                        ")->execute([$bid, $result['web_path'], $current_admin['admin_id']]);
                    }

                    $pdo->commit();
                    $action_msg = "Cover image updated for <strong>" . htmlspecialchars($bname) . "</strong>.";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    @unlink($result['fs_path']);
                    $action_err = "Could not save image: " . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
}

// --- Delete a branch's image (revert to default) -------------------------
if ($is_post && ($_POST['action'] ?? '') === 'delete_branch_image') {
    $bid = (int)($_POST['branch_id'] ?? 0);
    if ($bid <= 0) {
        $action_err = "Invalid branch.";
    } else {
        $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
        $stmt->execute([$bid]);
        $bname = $stmt->fetchColumn();
        if (!$bname) {
            $action_err = "Branch not found.";
        } else {
            $cur = $pdo->prepare("
                SELECT image_id, image_path
                FROM   branch_images
                WHERE  branch_id = ? AND is_primary = 1
                LIMIT  1
            ");
            $cur->execute([$bid]);
            $existing = $cur->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $action_err = "There is no uploaded image to delete for this branch.";
            } else {
                $pdo->prepare("DELETE FROM branch_images WHERE image_id = ?")
                    ->execute([$existing['image_id']]);
                $deleteBranchFile($existing['image_path']);
                $action_msg = "Image removed for <strong>" . htmlspecialchars($bname)
                            . "</strong>. The default placeholder will be shown until a new one is uploaded.";
            }
        }
    }
}

// --- GALLERY: upload one or more gallery images (caps at MAX_GALLERY_IMAGES)
if ($is_post && ($_POST['action'] ?? '') === 'upload_gallery_images') {
    $bid = (int)($_POST['branch_id'] ?? 0);

    if ($bid <= 0) {
        $action_err = "Invalid branch.";
    } else {
        $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
        $stmt->execute([$bid]);
        $bname = $stmt->fetchColumn();
        if (!$bname) {
            $action_err = "Branch not found.";
        } else {
            // Count current gallery images
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) FROM branch_images
                WHERE  branch_id = ? AND is_primary = 0
            ");
            $countStmt->execute([$bid]);
            $current = (int)$countStmt->fetchColumn();
            $remaining = MAX_GALLERY_IMAGES - $current;

            if ($remaining <= 0) {
                $action_err = "Gallery is full (max " . MAX_GALLERY_IMAGES
                            . " images per branch). Delete or replace existing images first.";
            } elseif (empty($_FILES['gallery_images']['name'][0])) {
                $action_err = "Please choose at least one image to upload.";
            } else {
                $names = $_FILES['gallery_images']['name'];
                $accepted = 0;
                $skipped  = 0;
                $errors   = [];

                // Determine current max sort_order so new images append cleanly
                $soStmt = $pdo->prepare("
                    SELECT COALESCE(MAX(sort_order), -1) FROM branch_images
                    WHERE  branch_id = ? AND is_primary = 0
                ");
                $soStmt->execute([$bid]);
                $nextSort = (int)$soStmt->fetchColumn() + 1;

                for ($i = 0, $n = count($names); $i < $n; $i++) {
                    if ($accepted >= $remaining) {
                        $skipped++;
                        continue;
                    }
                    if (($_FILES['gallery_images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $oneFile = [
                        'name'     => $_FILES['gallery_images']['name'][$i],
                        'type'     => $_FILES['gallery_images']['type'][$i],
                        'tmp_name' => $_FILES['gallery_images']['tmp_name'][$i],
                        'error'    => $_FILES['gallery_images']['error'][$i],
                        'size'     => $_FILES['gallery_images']['size'][$i],
                    ];
                    $r = $processBranchImage($oneFile, $bid);
                    if (!$r['ok']) {
                        $errors[] = htmlspecialchars($oneFile['name']) . ': ' . $r['error'];
                        continue;
                    }
                    try {
                        $pdo->prepare("
                            INSERT INTO branch_images
                                (branch_id, image_path, is_primary, sort_order, uploaded_by_admin_id)
                            VALUES (?, ?, 0, ?, ?)
                        ")->execute([$bid, $r['web_path'], $nextSort, $current_admin['admin_id']]);
                        $nextSort++;
                        $accepted++;
                    } catch (Exception $e) {
                        @unlink($r['fs_path']);
                        $errors[] = htmlspecialchars($oneFile['name']) . ': database error';
                    }
                }

                if ($accepted > 0) {
                    $msg = "Added <strong>{$accepted}</strong> image"
                         . ($accepted !== 1 ? 's' : '') . " to <strong>"
                         . htmlspecialchars($bname) . "</strong>'s gallery.";
                    if ($skipped > 0) {
                        $msg .= " {$skipped} skipped — gallery limit of "
                              . MAX_GALLERY_IMAGES . " reached.";
                    }
                    if (!empty($errors)) {
                        $msg .= "<br>Issues: " . implode('; ', $errors);
                    }
                    $action_msg = $msg;
                } else {
                    $action_err = empty($errors)
                        ? "No images were uploaded."
                        : "Upload failed: " . implode('; ', $errors);
                }
            }
        }
    }
}

// --- GALLERY: replace a single gallery image by image_id ----------------
if ($is_post && ($_POST['action'] ?? '') === 'replace_gallery_image') {
    $imgId = (int)($_POST['image_id'] ?? 0);
    $bid   = (int)($_POST['branch_id'] ?? 0);   // sanity-check ownership

    if ($imgId <= 0 || $bid <= 0) {
        $action_err = "Invalid request.";
    } elseif (!isset($_FILES['gallery_image']) || $_FILES['gallery_image']['error'] === UPLOAD_ERR_NO_FILE) {
        $action_err = "Please choose a replacement image.";
    } else {
        $verify = $pdo->prepare("
            SELECT image_path FROM branch_images
            WHERE  image_id   = ?
              AND  branch_id  = ?
              AND  is_primary = 0
            LIMIT  1
        ");
        $verify->execute([$imgId, $bid]);
        $existing = $verify->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $action_err = "That gallery image could not be found.";
        } else {
            $r = $processBranchImage($_FILES['gallery_image'], $bid);
            if (!$r['ok']) {
                $action_err = $r['error'];
            } else {
                try {
                    $pdo->prepare("
                        UPDATE branch_images
                        SET    image_path           = ?,
                               uploaded_at          = NOW(),
                               uploaded_by_admin_id = ?
                        WHERE  image_id = ?
                    ")->execute([$r['web_path'], $current_admin['admin_id'], $imgId]);
                    $deleteBranchFile($existing['image_path']);
                    $action_msg = "Gallery image replaced.";
                } catch (Exception $e) {
                    @unlink($r['fs_path']);
                    $action_err = "Could not replace gallery image: " . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
}

// --- GALLERY: delete a single gallery image by image_id -----------------
if ($is_post && ($_POST['action'] ?? '') === 'delete_gallery_image') {
    $imgId = (int)($_POST['image_id'] ?? 0);
    $bid   = (int)($_POST['branch_id'] ?? 0);

    if ($imgId <= 0 || $bid <= 0) {
        $action_err = "Invalid request.";
    } else {
        $verify = $pdo->prepare("
            SELECT image_path FROM branch_images
            WHERE  image_id   = ?
              AND  branch_id  = ?
              AND  is_primary = 0
            LIMIT  1
        ");
        $verify->execute([$imgId, $bid]);
        $existing = $verify->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $action_err = "That gallery image could not be found.";
        } else {
            $pdo->prepare("DELETE FROM branch_images WHERE image_id = ?")->execute([$imgId]);
            $deleteBranchFile($existing['image_path']);
            $action_msg = "Gallery image removed.";
        }
    }
}

// =========================================================================
// CUSTOMERS MANAGEMENT — POST handlers (any admin can add/edit/delete)
// =========================================================================
// Note: the address column has been dropped from `customers` (see
// migration.sql). The forms only collect name / email / contact number.

// --- Add a new customer record ------------------------------------------
if ($is_post && ($_POST['action'] ?? '') === 'add_customer') {
    $c_name    = trim($_POST['full_name']     ?? '');
    $c_email   = trim($_POST['email']         ?? '');
    $c_contact = trim($_POST['contact_number'] ?? '');

    if ($c_name === '' || $c_email === '') {
        $action_err = "Full name and email are required.";
    } elseif (!filter_var($c_email, FILTER_VALIDATE_EMAIL)) {
        $action_err = "Please provide a valid email address.";
    } else {
        // Reject duplicate email (UNIQUE INDEX on customers.email)
        $check = $pdo->prepare("SELECT customer_id FROM customers WHERE email = ? LIMIT 1");
        $check->execute([$c_email]);
        if ($check->fetchColumn()) {
            $action_err = "A customer with that email already exists.";
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO customers (full_name, email, contact_number)
                    VALUES (?, ?, ?)
                ")->execute([$c_name, $c_email, ($c_contact !== '' ? $c_contact : null)]);
                $action_msg = "Customer <strong>" . htmlspecialchars($c_name) . "</strong> added successfully.";
            } catch (Exception $e) {
                $action_err = "Could not add customer: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// --- Edit an existing customer -------------------------------------------
if ($is_post && ($_POST['action'] ?? '') === 'edit_customer') {
    $c_id      = (int)($_POST['customer_id']   ?? 0);
    $c_name    = trim($_POST['full_name']      ?? '');
    $c_email   = trim($_POST['email']          ?? '');
    $c_contact = trim($_POST['contact_number'] ?? '');

    if ($c_id <= 0) {
        $action_err = "Invalid customer.";
    } elseif ($c_name === '' || $c_email === '') {
        $action_err = "Full name and email are required.";
    } elseif (!filter_var($c_email, FILTER_VALIDATE_EMAIL)) {
        $action_err = "Please provide a valid email address.";
    } else {
        // Confirm the customer exists
        $check = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id = ? LIMIT 1");
        $check->execute([$c_id]);
        if (!$check->fetchColumn()) {
            $action_err = "Customer not found.";
        } else {
            // Reject email collision with a *different* customer
            $dup = $pdo->prepare("
                SELECT customer_id FROM customers
                WHERE email = ? AND customer_id <> ? LIMIT 1
            ");
            $dup->execute([$c_email, $c_id]);
            if ($dup->fetchColumn()) {
                $action_err = "Another customer already uses that email address.";
            } else {
                try {
                    $pdo->prepare("
                        UPDATE customers
                        SET full_name = ?, email = ?, contact_number = ?
                        WHERE customer_id = ?
                    ")->execute([$c_name, $c_email, ($c_contact !== '' ? $c_contact : null), $c_id]);

                    // Keep the linked users.username in sync if there's a login row
                    $pdo->prepare("
                        UPDATE users SET username = ?
                        WHERE customer_id = ? AND role = 'Customer'
                    ")->execute([$c_email, $c_id]);

                    $action_msg = "Customer <strong>" . htmlspecialchars($c_name) . "</strong> updated.";
                } catch (Exception $e) {
                    $action_err = "Could not update customer: " . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
}

// --- Delete a customer ---------------------------------------------------
// Reservations and feedback referencing this customer cascade away via the
// existing FK ON DELETE CASCADE; the linked user row (if any) is set to
// customer_id = NULL via FK ON DELETE SET NULL. We additionally remove the
// orphaned Customer-role login so it cannot be re-used.
if ($is_post && ($_POST['action'] ?? '') === 'delete_customer') {
    $c_id = (int)($_POST['customer_id'] ?? 0);

    if ($c_id <= 0) {
        $action_err = "Invalid customer.";
    } else {
        $stmt = $pdo->prepare("SELECT full_name FROM customers WHERE customer_id = ?");
        $stmt->execute([$c_id]);
        $cname = $stmt->fetchColumn();
        if (!$cname) {
            $action_err = "Customer not found.";
        } else {
            try {
                $pdo->beginTransaction();
                // Remove the linked Customer-role login (if any). Admin and
                // Staff accounts are never touched by a customer delete.
                $pdo->prepare("
                    DELETE FROM users
                    WHERE customer_id = ? AND role = 'Customer'
                ")->execute([$c_id]);
                $pdo->prepare("DELETE FROM customers WHERE customer_id = ?")->execute([$c_id]);
                $pdo->commit();
                $action_msg = "Customer <strong>" . htmlspecialchars($cname) . "</strong> deleted.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $action_err = "Could not delete customer: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// =========================================================================
// AMENITIES MANAGEMENT — POST handlers
// =========================================================================
// Schema (already normalized — see migration.sql):
//   amenities(amenity_id, branch_id FK, amenity_name, description,
//             availability ENUM('Available','Unavailable'))
// Customer-side amenities.php reads the same columns; toggles here are
// reflected immediately on the public page.

// --- Add a new amenity ---------------------------------------------------
if ($is_post && ($_POST['action'] ?? '') === 'add_amenity') {
    $a_branch = (int)($_POST['branch_id']     ?? 0);
    $a_name   = trim($_POST['amenity_name']   ?? '');
    $a_desc   = trim($_POST['description']    ?? '');
    $a_avail  = ($_POST['availability'] ?? 'Available') === 'Unavailable' ? 'Unavailable' : 'Available';

    if ($a_branch <= 0 || $a_name === '') {
        $action_err = "Branch and amenity name are required.";
    } else {
        // Confirm the branch exists
        $bstmt = $pdo->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
        $bstmt->execute([$a_branch]);
        $bname = $bstmt->fetchColumn();
        if (!$bname) {
            $action_err = "Selected branch does not exist.";
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO amenities (branch_id, amenity_name, description, availability)
                    VALUES (?, ?, ?, ?)
                ")->execute([$a_branch, $a_name, ($a_desc !== '' ? $a_desc : null), $a_avail]);
                $action_msg = "Amenity <strong>" . htmlspecialchars($a_name)
                            . "</strong> added to <strong>" . htmlspecialchars($bname) . "</strong>.";
            } catch (Exception $e) {
                $action_err = "Could not add amenity: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// --- Toggle an amenity's availability ------------------------------------
if ($is_post && ($_POST['action'] ?? '') === 'toggle_amenity_availability') {
    $a_id = (int)($_POST['amenity_id'] ?? 0);
    if ($a_id <= 0) {
        $action_err = "Invalid amenity.";
    } else {
        $cur = $pdo->prepare("SELECT amenity_name, availability FROM amenities WHERE amenity_id = ?");
        $cur->execute([$a_id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $action_err = "Amenity not found.";
        } else {
            $new_state = ($row['availability'] === 'Available') ? 'Unavailable' : 'Available';
            $pdo->prepare("UPDATE amenities SET availability = ? WHERE amenity_id = ?")
                ->execute([$new_state, $a_id]);
            $action_msg = "<strong>" . htmlspecialchars($row['amenity_name']) . "</strong> is now "
                        . ($new_state === 'Available'
                            ? "<span style='color:#155724;'>available</span>"
                            : "<span style='color:#721c24;'>unavailable</span>") . ".";
        }
    }
}

// --- Delete an amenity ---------------------------------------------------
if ($is_post && ($_POST['action'] ?? '') === 'delete_amenity') {
    $a_id = (int)($_POST['amenity_id'] ?? 0);
    if ($a_id <= 0) {
        $action_err = "Invalid amenity.";
    } else {
        $cur = $pdo->prepare("SELECT amenity_name FROM amenities WHERE amenity_id = ?");
        $cur->execute([$a_id]);
        $aname = $cur->fetchColumn();
        if (!$aname) {
            $action_err = "Amenity not found.";
        } else {
            $pdo->prepare("DELETE FROM amenities WHERE amenity_id = ?")->execute([$a_id]);
            $action_msg = "Amenity <strong>" . htmlspecialchars($aname) . "</strong> deleted.";
        }
    }
}

// =========================================================================
// View routing & data
// =========================================================================
$view = $_GET['view'] ?? 'dashboard';

// Auto-complete confirmed reservations whose date has passed
$pdo->query("
    UPDATE reservations
    SET status = 'Completed'
    WHERE status = 'Confirmed'
      AND reservation_date < CURDATE()
");

$stats = [];

// ── Analytics date filter ────────────────────────────────────────────────
$current_year  = (int)date('Y');
$current_month = (int)date('m');
$filter_month  = (isset($_GET['month']) && (int)$_GET['month'] >= 1 && (int)$_GET['month'] <= 12)
                 ? (int)$_GET['month'] : $current_month;
$filter_year   = (isset($_GET['year'])  && (int)$_GET['year']  >= 2020 && (int)$_GET['year']  <= $current_year + 1)
                 ? (int)$_GET['year']  : $current_year;
$is_default_period = ($filter_month === $current_month && $filter_year === $current_year);

// Year used by the Monthly Booking Trends chart (independent of the page filter)
$trend_year = (isset($_GET['trend_year']) && (int)$_GET['trend_year'] >= 2020 && (int)$_GET['trend_year'] <= $current_year + 1)
              ? (int)$_GET['trend_year'] : $current_year;

// Overview stats (existing — unchanged)
$stats['pending_reservations'] = $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'Pending' AND payment_status = 'Paid'")->fetchColumn();
$stats['todays_reservations']  = $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'Confirmed' AND reservation_date = CURDATE()")->fetchColumn();
$stats['this_month_bookings']  = $pdo->query("SELECT COUNT(*) FROM reservations WHERE MONTH(reservation_date) = $filter_month AND YEAR(reservation_date) = $filter_year AND status IN ('Confirmed', 'Completed')")->fetchColumn();
$stats['this_month_revenue']   = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM reservations WHERE MONTH(reservation_date) = $filter_month AND YEAR(reservation_date) = $filter_year AND status IN ('Confirmed', 'Completed')")->fetchColumn();
$stats['total_customers']      = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$stats['avg_rating']           = $pdo->query("SELECT COALESCE(AVG(rating), 0) FROM feedback WHERE MONTH(feedback_date) = $filter_month AND YEAR(feedback_date) = $filter_year")->fetchColumn();

// Monthly booking trends — now scoped to selected $trend_year
$trend_stmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(reservation_date, '%Y-%m') AS month,
        DATE_FORMAT(reservation_date, '%b %Y') AS month_label,
        SUM(CASE WHEN status IN ('Pending', 'Confirmed', 'Completed') THEN 1 ELSE 0 END) AS total_bookings,
        SUM(CASE WHEN status IN ('Confirmed', 'Completed') THEN 1 ELSE 0 END) AS successful_bookings,
        SUM(CASE WHEN status IN ('Confirmed', 'Completed') THEN total_amount ELSE 0 END) AS revenue,
        SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_bookings
    FROM reservations
    WHERE YEAR(reservation_date) = ?
    GROUP BY DATE_FORMAT(reservation_date, '%Y-%m'), DATE_FORMAT(reservation_date, '%b %Y')
    ORDER BY month ASC
");
$trend_stmt->execute([$trend_year]);
$monthly_data = $trend_stmt->fetchAll(PDO::FETCH_ASSOC);

// Available trend years for the year-picker (years that actually have data,
// ensuring the current year is always included).
$years_stmt = $pdo->query("
    SELECT DISTINCT YEAR(reservation_date) AS y
    FROM reservations
    WHERE reservation_date IS NOT NULL
    ORDER BY y DESC
");
$available_trend_years = array_map('intval', $years_stmt->fetchAll(PDO::FETCH_COLUMN));
if (!in_array($current_year, $available_trend_years, true)) {
    $available_trend_years[] = $current_year;
    rsort($available_trend_years);
}

// Branch performance & reservation type distribution (unchanged)
$branch_query = "
    SELECT
        b.branch_id, b.branch_name,
        SUM(CASE WHEN r.status IN ('Pending', 'Confirmed', 'Completed') THEN 1 ELSE 0 END) AS total_reservations,
        SUM(CASE WHEN r.status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
        SUM(CASE WHEN r.status = 'Completed' THEN 1 ELSE 0 END) AS completed_count,
        COALESCE(SUM(CASE WHEN r.status IN ('Confirmed', 'Completed') THEN r.total_amount ELSE 0 END), 0) AS total_revenue,
        COALESCE(
            SUM(CASE WHEN r.status IN ('Confirmed', 'Completed') THEN r.total_amount ELSE 0 END) /
            NULLIF(SUM(CASE WHEN r.status IN ('Confirmed', 'Completed') THEN 1 ELSE 0 END), 0)
        , 0) AS avg_revenue_per_booking,
        COALESCE((
            SELECT AVG(f.rating) FROM feedback f WHERE f.branch_id = b.branch_id
        ), 0) AS avg_rating
    FROM branches b
    LEFT JOIN reservations r ON b.branch_id = r.branch_id
        AND MONTH(r.reservation_date) = $filter_month
        AND YEAR(r.reservation_date)  = $filter_year
    GROUP BY b.branch_id, b.branch_name
    ORDER BY total_revenue DESC
";
$branch_stats = $pdo->query($branch_query)->fetchAll(PDO::FETCH_ASSOC);

$type_query = "
    SELECT reservation_type, COUNT(*) AS count, SUM(total_amount) AS revenue
    FROM reservations
    WHERE status IN ('Confirmed', 'Completed')
      AND MONTH(reservation_date) = $filter_month
      AND YEAR(reservation_date)  = $filter_year
    GROUP BY reservation_type
";
$reservation_types = $pdo->query($type_query)->fetchAll(PDO::FETCH_ASSOC);

// =========================================================================
// View-specific data
// =========================================================================
if ($view === 'customers') {
    $pageTitle = "Registered Customers";

    // Search — full name, email, or contact number (partial match, case-insensitive
    // by default for utf8/utf8mb4_*_ci collations). We use three DISTINCT named
    // placeholders rather than reusing :q because reusing a named placeholder
    // raises SQLSTATE[HY093] on native prepared statements (i.e. when
    // PDO::ATTR_EMULATE_PREPARES is false). This was the root cause of the
    // search bar appearing to do nothing.
    $cust_search = trim($_GET['q'] ?? '');
    $where_sql = '';
    $params = [];
    if ($cust_search !== '') {
        $where_sql = "WHERE full_name      LIKE :q_name
                         OR email          LIKE :q_email
                         OR contact_number LIKE :q_contact";
        $like = '%' . $cust_search . '%';
        $params[':q_name']    = $like;
        $params[':q_email']   = $like;
        $params[':q_contact'] = $like;
    }

    // Pagination
    $cust_per_page = 10;
    $cust_page     = max(1, (int)($_GET['page'] ?? 1));

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM customers $where_sql");
    $count_stmt->execute($params);
    $cust_total = (int)$count_stmt->fetchColumn();
    $cust_pages = max(1, (int)ceil($cust_total / $cust_per_page));
    $cust_page  = min($cust_page, $cust_pages);
    $cust_offset = ($cust_page - 1) * $cust_per_page;

    // Note: address column has been dropped from `customers` (see migration.sql),
    // so we explicitly list the columns the dashboard consumes — using SELECT *
    // would still work but is brittle if the schema changes again.
    $sql = "SELECT customer_id, full_name, email, contact_number, created_at
            FROM customers
            $where_sql
            ORDER BY customer_id DESC
            LIMIT $cust_per_page OFFSET $cust_offset";
    $list_stmt = $pdo->prepare($sql);
    $list_stmt->execute($params);
    $data = $list_stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($view === 'amenities') {
    $pageTitle = "Amenities Management";

    // Branches — needed for the "Add Amenity" branch dropdown.
    $amenity_branches = $pdo->query("
        SELECT branch_id, branch_name
        FROM branches
        ORDER BY branch_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // All amenities, grouped by branch in the view.
    $amenities_data = $pdo->query("
        SELECT a.amenity_id, a.branch_id, a.amenity_name, a.description, a.availability,
               b.branch_name
        FROM amenities a
        JOIN branches b ON a.branch_id = b.branch_id
        ORDER BY b.branch_name, a.amenity_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $amenities_by_branch = [];
    foreach ($amenities_data as $am) {
        $amenities_by_branch[(int)$am['branch_id']][] = $am;
    }

} elseif ($view === 'analytics') {
    $pageTitle = "Booking Analytics";

} elseif ($view === 'branches') {
    $pageTitle = "Branches Management";
    $branches_data = $pdo->query("
        SELECT branch_id, branch_name, location, contact_number, opening_hours,
               image_url, is_available, unavailable_reason, status_updated_at
        FROM   v_branches_full
        ORDER  BY branch_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $globalMaintenance = (int)$pdo->query("
        SELECT setting_value FROM system_settings
        WHERE  setting_key = 'all_branches_maintenance'
        LIMIT 1
    ")->fetchColumn();

    // Gallery images grouped by branch_id (single query, then bucket)
    $gallery_rows = $pdo->query("
        SELECT image_id, branch_id, image_path, sort_order, uploaded_at
        FROM   branch_images
        WHERE  is_primary = 0
        ORDER  BY branch_id, sort_order, image_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $gallery_by_branch = [];   // [branch_id => [ {image_id, image_path, ...}, ... ]]
    foreach ($gallery_rows as $g) {
        $gallery_by_branch[(int)$g['branch_id']][] = $g;
    }

} elseif ($view === 'admins') {
    $pageTitle = "Admin Management";
    $admins = $pdo->query("
        SELECT ap.admin_id, ap.full_name, ap.is_super_admin, ap.created_at,
               ap.last_password_change, ap.must_change_password,
               u.username, u.user_id, u.is_active,
               creator.full_name AS created_by_name
        FROM admin_profiles ap
        JOIN users u             ON u.user_id = ap.user_id
        LEFT JOIN admin_profiles creator ON creator.admin_id = ap.created_by_admin_id
        ORDER BY ap.is_super_admin DESC, ap.created_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

} elseif ($view === 'feedback') {
    $pageTitle = "User Feedback";

    $fb_per_page = 12;
    $fb_page     = max(1, (int)($_GET['page'] ?? 1));

    $allowed_ratings = ['All', '1', '2', '3', '4', '5'];
    $filter_rating   = (isset($_GET['rating']) && in_array($_GET['rating'], $allowed_ratings))
                       ? $_GET['rating'] : 'All';
    $rating_where    = ($filter_rating !== 'All') ? "WHERE f.rating = " . (int)$filter_rating : "";

    $fb_total  = $pdo->query("SELECT COUNT(*) FROM feedback f $rating_where")->fetchColumn();
    $fb_pages  = max(1, (int)ceil($fb_total / $fb_per_page));
    $fb_page   = min($fb_page, $fb_pages);
    $fb_offset = ($fb_page - 1) * $fb_per_page;

    $feedback_data = $pdo->query("
        SELECT f.*, c.full_name, b.branch_name
        FROM feedback f
        LEFT JOIN customers c ON f.customer_id = c.customer_id
        LEFT JOIN branches  b ON f.branch_id   = b.branch_id
        $rating_where
        ORDER BY f.feedback_date DESC
        LIMIT $fb_per_page OFFSET $fb_offset
    ")->fetchAll(PDO::FETCH_ASSOC);

    $fb_stats['total'] = $pdo->query("SELECT COUNT(*) FROM feedback")->fetchColumn();
    $fb_stats['avg']   = $pdo->query("SELECT COALESCE(AVG(rating),0) FROM feedback")->fetchColumn();
    $fb_stats['5star'] = $pdo->query("SELECT COUNT(*) FROM feedback WHERE rating = 5")->fetchColumn();
    $fb_stats['1star'] = $pdo->query("SELECT COUNT(*) FROM feedback WHERE rating = 1")->fetchColumn();

} else {
    $allowed_statuses = ['All', 'Pending', 'Confirmed', 'Completed', 'Cancelled'];
    $filter_status = isset($_GET['status']) && in_array($_GET['status'], $allowed_statuses)
                     ? $_GET['status'] : 'All';

    // ── Reservations Month/Year filter ────────────────────────────────────
    // 'All' (the default) means "don't restrict by date". Picking a specific
    // month and/or year narrows the list down. The filter is intentionally
    // permissive — month-only and year-only are both valid combinations,
    // matching the compact UI control rendered below.
    $allowed_res_month = (isset($_GET['res_month']) && $_GET['res_month'] !== 'All'
                          && (int)$_GET['res_month'] >= 1 && (int)$_GET['res_month'] <= 12)
                          ? (int)$_GET['res_month'] : null;
    $allowed_res_year  = (isset($_GET['res_year']) && $_GET['res_year'] !== 'All'
                          && (int)$_GET['res_year'] >= 2020 && (int)$_GET['res_year'] <= $current_year + 1)
                          ? (int)$_GET['res_year']  : null;

    $exclude_holds = "NOT (r.status = 'Pending' AND r.payment_status = 'Unpaid')";

    $where_clause = "WHERE " . $exclude_holds;
    if ($filter_status !== 'All') {
        $where_clause .= " AND r.status = " . $pdo->quote($filter_status);
    }
    if ($allowed_res_month !== null) {
        $where_clause .= " AND MONTH(r.reservation_date) = " . (int)$allowed_res_month;
    }
    if ($allowed_res_year !== null) {
        $where_clause .= " AND YEAR(r.reservation_date) = " . (int)$allowed_res_year;
    }

    $per_page     = 10;
    $current_page = max(1, (int)($_GET['page'] ?? 1));
    $total_rows   = $pdo->query("SELECT COUNT(*) FROM reservations r $where_clause")->fetchColumn();
    $total_pages  = max(1, (int)ceil($total_rows / $per_page));
    $current_page = min($current_page, $total_pages);
    $offset       = ($current_page - 1) * $per_page;

    $data = $pdo->query("
        SELECT r.*, c.full_name, b.branch_name
        FROM reservations r
        JOIN customers c ON r.customer_id = c.customer_id
        JOIN branches  b ON r.branch_id   = b.branch_id
        $where_clause
        ORDER BY r.reservation_id DESC
        LIMIT $per_page OFFSET $offset
    ")->fetchAll();

    // Distinct years that actually have reservations — populates the year
    // dropdown in the filter bar so admins only see meaningful options.
    $res_year_list = array_map('intval', $pdo->query("
        SELECT DISTINCT YEAR(reservation_date) AS y
        FROM reservations
        WHERE reservation_date IS NOT NULL
        ORDER BY y DESC
    ")->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array($current_year, $res_year_list, true)) {
        $res_year_list[] = $current_year;
        rsort($res_year_list);
    }

    $pageTitle = "Reservation Overview";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel | CheckMates</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: linear-gradient(rgba(0, 119, 182, 0.1), rgba(0, 119, 182, 0.1)),
                        url('https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=1920&q=80');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
        }

        .sidebar {
            width: 220px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            height: 100vh;
            position: sticky;
            top: 0;
            border-right: 1px solid rgba(255, 255, 255, 0.3);
            display: flex;
            flex-direction: column;
            padding: 1.5rem 1rem;
            box-shadow: 4px 0 15px rgba(0,0,0,0.05);
            gap: 6px;
        }

        .brand {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 10px;
        }

        .nav-links { list-style: none; display: flex; flex-direction: column; gap: 4px; }
        .nav-links a {
            text-decoration: none; color: var(--text-dark);
            padding: 12px 20px; border-radius: 12px; transition: 0.3s;
            display: flex; align-items: center; gap: 12px; font-weight: 500;
        }
        .nav-links a:hover, .nav-links a.active {
            background: var(--primary); color: white;
            box-shadow: 0 4px 15px rgba(0, 119, 182, 0.3);
            transform: translateX(5px);
        }

        .logout-btn { margin-top: 8px; color: #ef476f !important; border: 1px solid #ef476f; }
        .logout-btn:hover { background: #ef476f !important; color: white !important; }

        .main-content { flex: 1; padding: 3rem; overflow-y: auto; }

        .glass-panel {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.4);
            animation: fadeIn 0.5s ease-out;
            margin-bottom: 2rem;
        }

        h1 { color: var(--primary-dark); margin-bottom: 1.5rem; font-size: 2rem; }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white; padding: 1.5rem; border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.12); }
        .stat-card h3 { color: #7f8c8d; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.5rem; text-transform: uppercase; }
        .stat-card .stat-value { font-size: 2rem; font-weight: bold; color: #2c3e50; margin-bottom: 0.3rem; }
        .stat-card .stat-label { color: #95a5a6; font-size: 0.8rem; }
        .stat-card.primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .stat-card.primary h3, .stat-card.primary .stat-value, .stat-card.primary .stat-label { color: white; }
        .stat-card.success { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; }
        .stat-card.success h3, .stat-card.success .stat-value, .stat-card.success .stat-label { color: white; }
        .stat-card.info { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; }
        .stat-card.info h3, .stat-card.info .stat-value, .stat-card.info .stat-label { color: white; }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .chart-card {
            background: white; padding: 1.5rem; border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .chart-card h2 { color: #2c3e50; margin-bottom: 1rem; font-size: 1.1rem; font-weight: 600; }
        .chart-container { position: relative; height: 300px; }

        /* Tables */
        table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 1rem; }
        th { background: var(--primary); color: white; padding: 15px; text-align: left; font-weight: 600; }
        th:first-child { border-top-left-radius: 8px; }
        th:last-child  { border-top-right-radius: 8px; }
        td { padding: 15px; border-bottom: 1px solid rgba(0,0,0,0.05); color: #444; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(0, 119, 182, 0.05); }

        .status { padding: 6px 12px; border-radius: 50px; font-size: 0.85rem; font-weight: 600; }
        .status.confirmed  { background: #d4edda; color: #155724; }
        .status.pending    { background: #fff3cd; color: #856404; }
        .status.cancelled  { background: #f8d7da; color: #721c24; }
        .status.completed  { background: #cce5ff; color: #004085; }

        .btn-action {
            text-decoration: none; padding: 6px 15px; border-radius: 6px;
            font-size: 0.85rem; font-weight: 600; transition: 0.2s;
            background: var(--primary); color: white; display: inline-block; white-space: nowrap;
            border: none; cursor: pointer;
        }
        .btn-action:hover { filter: brightness(1.15); transform: scale(1.05); }
        .btn-action.btn-reject { background: #ef476f; }
        .btn-action.btn-secondary { background: white; color: var(--primary); border: 2px solid var(--primary); }
        .btn-action.btn-secondary:hover { background: var(--primary); color: white; }

        .action-buttons { display: flex; gap: 8px; align-items: center; flex-wrap: nowrap; }

        @media (max-width: 768px) {
            .charts-grid { grid-template-columns: 1fr; }
            .stats-grid  { grid-template-columns: 1fr; }
        }

        /* Filter Bar */
        .filter-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .filter-bar > span { font-weight: 600; color: #555; font-size: 0.9rem; }
        .filter-btn {
            text-decoration: none; padding: 7px 16px; border-radius: 50px;
            font-size: 0.83rem; font-weight: 600; border: 2px solid #ddd;
            color: #555; background: white; transition: 0.2s; cursor: pointer;
        }
        .filter-btn:hover { border-color: var(--primary); color: var(--primary); }
        .filter-btn.active { background: var(--primary); border-color: var(--primary); color: white; box-shadow: 0 3px 10px rgba(0,119,182,0.3); }
        .filter-btn.f-pending.active   { background: #856404; border-color: #856404; }
        .filter-btn.f-confirmed.active { background: #155724; border-color: #155724; }
        .filter-btn.f-completed.active { background: #004085; border-color: #004085; }
        .filter-btn.f-cancelled.active { background: #721c24; border-color: #721c24; }

        /* Pagination */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 5px; margin-top: 1.5rem; flex-wrap: wrap; }
        .page-btn {
            text-decoration: none; padding: 8px 13px; border-radius: 8px;
            font-size: 0.875rem; font-weight: 600; border: 2px solid #ddd;
            color: #555; background: white; transition: 0.2s; min-width: 38px; text-align: center;
        }
        .page-btn:hover { border-color: var(--primary); color: var(--primary); }
        .page-btn.active { background: var(--primary); border-color: var(--primary); color: white; box-shadow: 0 3px 10px rgba(0,119,182,0.3); }
        .page-btn.disabled { opacity: 0.35; pointer-events: none; }
        .page-info { font-size: 0.82rem; color: #999; margin-left: 6px; }

        /* ── Feedback Section ───────────────────────────────────── */
        .feedback-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1.2rem; margin-bottom: 2rem; }
        .fb-stat { background: white; border-radius: 14px; padding: 1.2rem 1.5rem; box-shadow: 0 4px 15px rgba(0,0,0,0.07); display: flex; align-items: center; gap: 14px; transition: transform 0.2s, box-shadow 0.2s; }
        .fb-stat:hover { transform: translateY(-4px); box-shadow: 0 8px 22px rgba(0,0,0,0.11); }
        .fb-stat .fb-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .fb-stat .fb-icon.blue  { background: rgba(102,126,234,0.15); color: #667eea; }
        .fb-stat .fb-icon.gold  { background: rgba(243,156,18,0.15);  color: #f39c12; }
        .fb-stat .fb-icon.green { background: rgba(46,204,113,0.15);  color: #27ae60; }
        .fb-stat .fb-icon.red   { background: rgba(239,71,111,0.15);  color: #ef476f; }
        .fb-stat .fb-text strong { display: block; font-size: 1.6rem; font-weight: 700; color: #2c3e50; line-height: 1; }
        .fb-stat .fb-text span   { font-size: 0.78rem; color: #95a5a6; text-transform: uppercase; letter-spacing: 0.04em; }

        .filter-btn.f-rating.active { background: #f39c12; border-color: #f39c12; color: white; box-shadow: 0 3px 10px rgba(243,156,18,0.35); }

        .feedback-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.2rem; margin-top: 0.5rem; }
        .feedback-card { background: white; border-radius: 16px; padding: 1.4rem 1.5rem; box-shadow: 0 4px 15px rgba(0,0,0,0.07); border-left: 4px solid var(--primary); display: flex; flex-direction: column; gap: 10px; transition: transform 0.2s, box-shadow 0.2s; position: relative; }
        .feedback-card:hover { transform: translateY(-4px); box-shadow: 0 10px 28px rgba(0,0,0,0.11); }
        .feedback-card .fc-header { display: flex; align-items: center; gap: 12px; }
        .fc-avatar { width: 38px; height: 38px; border-radius: 50%; background: var(--secondary); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.95rem; flex-shrink: 0; }
        .fc-meta strong { display: block; font-size: 0.95rem; color: #2c3e50; }
        .fc-meta span   { font-size: 0.78rem; color: #95a5a6; }
        .fc-stars { color: #f39c12; font-size: 0.95rem; letter-spacing: 2px; }
        .fc-stars.low { color: #e74c3c; }
        .fc-stars.mid { color: #f39c12; }
        .fc-stars.high { color: #27ae60; }
        .fc-comment { font-size: 0.9rem; color: #555; line-height: 1.55; border-top: 1px solid rgba(0,0,0,0.06); padding-top: 10px; margin-top: 2px; font-style: italic; }
        .fc-footer { display: flex; justify-content: space-between; align-items: center; font-size: 0.78rem; color: #aaa; margin-top: auto; padding-top: 8px; border-top: 1px solid rgba(0,0,0,0.05); }
        .fc-branch { background: rgba(0,119,182,0.1); color: var(--primary-dark); padding: 3px 10px; border-radius: 50px; font-size: 0.76rem; font-weight: 600; }
        .fc-badge { position: absolute; top: 14px; right: 14px; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700; color: white; }
        .fc-badge.r5,.fc-badge.r4 { background: #27ae60; }
        .fc-badge.r3               { background: #f39c12; }
        .fc-badge.r2,.fc-badge.r1  { background: #e74c3c; }

        @media (max-width: 768px) {
            .feedback-grid    { grid-template-columns: 1fr; }
            .feedback-summary { grid-template-columns: 1fr 1fr; }
        }

        /* ─────────────────────────────────────────────────────────────
           ANALYTICS DATE FILTER (REDESIGNED)
           Two-row layout: quick presets row + custom selector row, all
           wrapped in one cohesive card that matches the system's blue.
           ───────────────────────────────────────────────────────────── */
        .analytics-filter-card {
            background: linear-gradient(135deg, rgba(0,119,182,0.06) 0%, rgba(102,126,234,0.06) 100%);
            border: 1px solid rgba(0,119,182,0.18);
            border-radius: 18px;
            padding: 1.2rem 1.4rem;
            margin-bottom: 1.8rem;
            box-shadow: 0 4px 18px rgba(0,119,182,0.08);
        }
        .analytics-filter-card .af-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 0.9rem; flex-wrap: wrap; gap: 0.6rem;
        }
        .analytics-filter-card .af-title {
            font-size: 0.95rem; font-weight: 700; color: var(--primary-dark);
            display: flex; align-items: center; gap: 8px;
        }
        .analytics-filter-card .af-title i { color: var(--primary); }
        .af-period-badge-new {
            font-size: 0.78rem; color: white;
            background: var(--primary);
            padding: 6px 14px; border-radius: 50px;
            font-weight: 600; white-space: nowrap;
            display: inline-flex; align-items: center; gap: 6px;
            box-shadow: 0 3px 10px rgba(0,119,182,0.25);
        }

        .af-presets {
            display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 0.9rem;
        }
        .af-preset {
            text-decoration: none; padding: 7px 14px; border-radius: 50px;
            font-size: 0.78rem; font-weight: 600; border: 1.5px solid rgba(0,119,182,0.25);
            color: var(--primary); background: white; transition: 0.2s; cursor: pointer;
            display: inline-flex; align-items: center; gap: 5px;
        }
        .af-preset:hover { border-color: var(--primary); background: rgba(0,119,182,0.08); }
        .af-preset.active {
            background: var(--primary); border-color: var(--primary); color: white;
            box-shadow: 0 3px 10px rgba(0,119,182,0.3);
        }

        .af-custom-row {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            padding-top: 0.8rem; border-top: 1px dashed rgba(0,119,182,0.2);
        }
        .af-custom-row .af-custom-label {
            font-size: 0.82rem; font-weight: 600; color: #555;
            display: flex; align-items: center; gap: 6px;
        }
        .af-select {
            appearance: none; -webkit-appearance: none;
            background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24'%3E%3Cpath fill='%230077b6' d='M7 10l5 5 5-5z'/%3E%3C/svg%3E") no-repeat right 10px center;
            border: 2px solid rgba(0,119,182,0.2); border-radius: 50px;
            padding: 7px 32px 7px 14px; font-size: 0.85rem; font-weight: 600;
            color: #2c3e50; cursor: pointer; outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .af-select:hover, .af-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,119,182,0.12);
        }
        .af-apply-btn {
            background: var(--primary); color: white; border: none;
            padding: 8px 22px; border-radius: 50px; font-size: 0.85rem; font-weight: 600;
            cursor: pointer; transition: filter 0.2s, transform 0.15s, box-shadow 0.2s;
            display: inline-flex; align-items: center; gap: 6px;
            box-shadow: 0 3px 10px rgba(0,119,182,0.3);
        }
        .af-apply-btn:hover { filter: brightness(1.12); transform: translateY(-1px); box-shadow: 0 5px 15px rgba(0,119,182,0.4); }
        .af-reset-link {
            font-size: 0.8rem; color: #999; text-decoration: none;
            padding: 7px 14px; border-radius: 50px;
            transition: color 0.2s, background 0.2s;
        }
        .af-reset-link:hover { color: #ef476f; background: rgba(239,71,111,0.07); }

        @media (max-width: 768px) {
            .af-custom-row { flex-direction: column; align-items: stretch; }
            .af-custom-row .af-select, .af-custom-row .af-apply-btn { width: 100%; justify-content: center; }
        }

        /* ─────────────────────────────────────────────────────────────
           TREND-YEAR NAVIGATOR (in Monthly Booking Trends card)
           ───────────────────────────────────────────────────────────── */
        .trend-year-nav {
            display: inline-flex; align-items: center; gap: 4px;
            background: rgba(0,119,182,0.08); border-radius: 50px; padding: 3px;
        }
        .trend-year-nav a, .trend-year-nav button {
            text-decoration: none; border: none; background: transparent;
            color: var(--primary); font-weight: 700; font-size: 0.82rem;
            padding: 5px 10px; border-radius: 50px; cursor: pointer;
            display: inline-flex; align-items: center; gap: 5px;
            transition: 0.2s;
        }
        .trend-year-nav a.disabled { opacity: 0.35; pointer-events: none; }
        .trend-year-nav a:hover, .trend-year-nav button:hover {
            background: white; box-shadow: 0 2px 6px rgba(0,119,182,0.15);
        }
        .trend-year-current {
            background: var(--primary) !important; color: white !important;
            padding: 5px 14px !important; box-shadow: 0 2px 8px rgba(0,119,182,0.3);
            position: relative;
        }
        .trend-year-dropdown {
            position: relative;
        }
        .trend-year-menu {
            display: none; position: absolute; top: 100%; right: 0; margin-top: 6px;
            background: white; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            border: 1px solid rgba(0,119,182,0.15); padding: 6px; z-index: 50;
            min-width: 110px; max-height: 240px; overflow-y: auto;
        }
        .trend-year-menu.open { display: block; }
        .trend-year-menu a {
            display: block; padding: 7px 14px; border-radius: 8px;
            color: #444; font-size: 0.85rem; font-weight: 500; text-decoration: none;
        }
        .trend-year-menu a:hover { background: rgba(0,119,182,0.08); color: var(--primary); }
        .trend-year-menu a.active { background: var(--primary); color: white; font-weight: 700; }

        .chart-card-header {
            display: flex; justify-content: space-between; align-items: center;
            gap: 10px; flex-wrap: wrap; margin-bottom: 1rem;
        }
        .chart-card-header h2 { margin: 0 !important; }

        /* ─────────────────────────────────────────────────────────────
           ALERT BANNERS (used by all admin actions)
           ───────────────────────────────────────────────────────────── */
        .alert {
            padding: 12px 18px; border-radius: 12px; margin-bottom: 1.2rem;
            display: flex; align-items: flex-start; gap: 10px;
            font-size: 0.9rem; animation: fadeIn 0.4s ease-out;
        }
        .alert i { margin-top: 2px; flex-shrink: 0; }
        .alert.success { background: #d4edda; color: #155724; border-left: 4px solid #155724; }
        .alert.error   { background: #f8d7da; color: #721c24; border-left: 4px solid #721c24; }

        /* ─────────────────────────────────────────────────────────────
           ADMIN MANAGEMENT VIEW
           ───────────────────────────────────────────────────────────── */
        .admin-toolbar {
            display: flex; justify-content: space-between; align-items: center;
            gap: 1rem; flex-wrap: wrap; margin-bottom: 1.2rem;
        }
        .admin-toolbar .toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        .badge-pill {
            padding: 4px 12px; border-radius: 50px;
            font-size: 0.72rem; font-weight: 700; letter-spacing: 0.03em;
            display: inline-flex; align-items: center; gap: 5px;
        }
        .badge-super  { background: linear-gradient(135deg, #f6d365, #fda085); color: #6b3e00; }
        .badge-admin  { background: rgba(0,119,182,0.12); color: var(--primary-dark); }
        .badge-warn   { background: rgba(243,156,18,0.15); color: #b06d00; }

        /* ─────────────────────────────────────────────────────────────
           CUSTOMERS — search + export toolbar
           ───────────────────────────────────────────────────────────── */
        .customers-toolbar {
            display: flex; justify-content: space-between; align-items: center;
            gap: 1rem; flex-wrap: wrap; margin-bottom: 1.2rem;
        }
        .search-box {
            display: flex; align-items: center; gap: 0;
            background: white; border: 2px solid rgba(0,119,182,0.2);
            border-radius: 50px; padding: 4px 4px 4px 16px;
            transition: border-color 0.2s, box-shadow 0.2s;
            min-width: 280px;
        }
        .search-box:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,119,182,0.12);
        }
        .search-box i { color: var(--primary); margin-right: 8px; }
        .search-box input {
            border: none; outline: none; flex: 1;
            font-size: 0.88rem; padding: 7px 4px; background: transparent;
            color: #2c3e50;
        }
        .search-box button {
            background: var(--primary); color: white; border: none;
            padding: 7px 18px; border-radius: 50px; font-weight: 600;
            cursor: pointer; font-size: 0.82rem;
            transition: filter 0.2s;
        }
        .search-box button:hover { filter: brightness(1.12); }
        .search-clear {
            text-decoration: none; color: #999; font-size: 0.8rem;
            padding: 7px 12px; border-radius: 50px;
            transition: color 0.2s, background 0.2s;
        }
        .search-clear:hover { color: #ef476f; background: rgba(239,71,111,0.07); }

        .export-btn {
            background: linear-gradient(135deg, #20bf6b 0%, #0fb9b1 100%);
            color: white; border: none; text-decoration: none;
            padding: 9px 20px; border-radius: 50px;
            font-size: 0.85rem; font-weight: 600;
            display: inline-flex; align-items: center; gap: 7px;
            cursor: pointer; transition: filter 0.2s, transform 0.15s, box-shadow 0.2s;
            box-shadow: 0 3px 10px rgba(32,191,107,0.3);
        }
        .export-btn:hover { filter: brightness(1.1); transform: translateY(-1px); box-shadow: 0 5px 15px rgba(32,191,107,0.4); }

        /* ─────────────────────────────────────────────────────────────
           MODAL (used by Add Admin & Change Password)
           ───────────────────────────────────────────────────────────── */
        .modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(20, 30, 50, 0.55);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center; justify-content: center;
            animation: fadeIn 0.2s ease-out;
            padding: 1rem;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: white; border-radius: 20px;
            padding: 2rem; max-width: 480px; width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalIn 0.3s ease-out;
        }
        @keyframes modalIn { from { opacity: 0; transform: translateY(20px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @keyframes fadeIn  { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .modal h2 {
            font-size: 1.25rem; color: var(--primary-dark); margin: 0 0 0.4rem;
            display: flex; align-items: center; gap: 10px;
        }
        .modal h2 i { color: var(--primary); }
        .modal .modal-subtitle { color: #888; font-size: 0.85rem; margin-bottom: 1.2rem; }
        .modal .form-group { margin-bottom: 1rem; }
        .modal label {
            display: block; font-size: 0.82rem; font-weight: 600;
            color: #555; margin-bottom: 6px;
        }
        .modal input[type="text"], .modal input[type="email"], .modal input[type="password"] {
            width: 100%; padding: 11px 14px; border-radius: 10px;
            border: 2px solid #e3e8ee; font-size: 0.9rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box; font-family: inherit;
        }
        .modal input:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,119,182,0.12);
        }
        .modal .modal-info {
            background: rgba(0,119,182,0.07);
            border-left: 3px solid var(--primary);
            padding: 10px 14px; border-radius: 8px;
            font-size: 0.82rem; color: #444; margin-bottom: 1.2rem;
        }
        .modal-actions {
            display: flex; justify-content: flex-end; gap: 8px; margin-top: 1.4rem;
        }
        .btn-cancel {
            padding: 9px 20px; border-radius: 50px;
            border: 2px solid #ddd; background: white; color: #555;
            font-weight: 600; font-size: 0.85rem; cursor: pointer;
            transition: 0.2s; font-family: inherit;
        }
        .btn-cancel:hover { border-color: #999; color: #333; }
        .btn-submit {
            padding: 9px 24px; border-radius: 50px;
            background: var(--primary); color: white; border: none;
            font-weight: 600; font-size: 0.85rem; cursor: pointer;
            transition: filter 0.2s, transform 0.15s, box-shadow 0.2s;
            box-shadow: 0 3px 10px rgba(0,119,182,0.3);
            font-family: inherit;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-submit:hover { filter: brightness(1.12); transform: translateY(-1px); box-shadow: 0 5px 15px rgba(0,119,182,0.4); }

        /* ─────────────────────────────────────────────────────────────
           PASSWORD FIELD — show / hide toggle (Admins → Change Password)
           ─────────────────────────────────────────────────────────────
           A relative wrapper around the <input> hosts an absolutely
           positioned eye button. The button toggles the input's `type`
           between "password" and "text" purely on the client; the value
           is still hashed via password_hash() on submit.
        */
        .password-field {
            position: relative;
            display: block;
        }
        .modal .password-field input[type="password"],
        .modal .password-field input[type="text"] {
            /* Reserve room for the eye button on the right edge */
            padding-right: 46px;
        }
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 6px;
            transform: translateY(-50%);
            width: 34px;
            height: 34px;
            background: transparent;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            color: #8a96a3;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            transition: color 0.18s, background 0.18s, box-shadow 0.18s;
            font-family: inherit;
        }
        .password-toggle:hover {
            color: var(--primary);
            background: rgba(0,119,182,0.08);
        }
        .password-toggle:focus-visible {
            outline: none;
            color: var(--primary);
            background: rgba(0,119,182,0.10);
            box-shadow: 0 0 0 2px rgba(0,119,182,0.22);
        }
        .password-toggle i { pointer-events: none; }
        /* Hide the native "reveal" widget Edge/IE add to password inputs so
           we don't end up with two competing eye icons. */
        .password-field input::-ms-reveal,
        .password-field input::-ms-clear { display: none; }

        /* ─────────────────────────────────────────────────────────────
           BRANCHES MANAGEMENT VIEW
           ───────────────────────────────────────────────────────────── */
        .maint-toggle-card {
            background: linear-gradient(135deg, #fff8e6 0%, #ffe8a3 100%);
            border: 1px solid rgba(243,156,18,0.35);
            border-left: 5px solid #f39c12;
            border-radius: 16px;
            padding: 1.2rem 1.5rem;
            margin-bottom: 1.8rem;
            box-shadow: 0 4px 18px rgba(243,156,18,0.12);
            display: flex;
            align-items: center;
            gap: 1.2rem;
            flex-wrap: wrap;
        }
        .maint-toggle-card.is-on {
            background: linear-gradient(135deg, #ffe9e5 0%, #ffc7bd 100%);
            border-color: rgba(231,76,60,0.4);
            border-left-color: #e74c3c;
            box-shadow: 0 4px 18px rgba(231,76,60,0.15);
        }
        .maint-toggle-card .mt-icon {
            width: 48px; height: 48px; border-radius: 12px;
            background: rgba(243,156,18,0.18); color: #b06d00;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
        }
        .maint-toggle-card.is-on .mt-icon {
            background: rgba(231,76,60,0.18); color: #c0392b;
        }
        .maint-toggle-card .mt-text { flex: 1; min-width: 240px; }
        .maint-toggle-card .mt-text strong {
            display: block; font-size: 1rem; color: #6b3e00; margin-bottom: 3px;
        }
        .maint-toggle-card.is-on .mt-text strong { color: #7d2a20; }
        .maint-toggle-card .mt-text span {
            font-size: 0.85rem; color: #8a5a00;
        }
        .maint-toggle-card.is-on .mt-text span { color: #a04036; }

        /* Toggle switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 56px;
            height: 30px;
            flex-shrink: 0;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute; cursor: pointer;
            inset: 0;
            background: #cfd6dd;
            border-radius: 30px;
            transition: 0.3s;
        }
        .toggle-slider::before {
            position: absolute; content: '';
            height: 24px; width: 24px;
            left: 3px; top: 3px;
            background: white; border-radius: 50%;
            transition: 0.3s;
            box-shadow: 0 2px 6px rgba(0,0,0,0.18);
        }
        input:checked + .toggle-slider { background: #e74c3c; }
        input:checked + .toggle-slider::before { transform: translateX(26px); }

        /* Branch admin grid */
        .branch-admin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
            gap: 1.5rem;
        }
        .branch-admin-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 18px rgba(0,0,0,0.07);
            display: flex;
            flex-direction: column;
            transition: box-shadow 0.25s, transform 0.25s;
        }
        .branch-admin-card:hover {
            box-shadow: 0 10px 28px rgba(0,119,182,0.15);
            transform: translateY(-3px);
        }

        .branch-admin-imgbox {
            position: relative;
            width: 100%;
            aspect-ratio: 16/10;
            background: #eef3f8;
            overflow: hidden;
        }
        .branch-admin-imgbox img {
            width: 100%; height: 100%;
            object-fit: cover;
            display: block;
        }
        .branch-admin-imgbox.is-blocked img {
            filter: grayscale(55%) brightness(0.88);
        }
        .branch-admin-status {
            position: absolute;
            top: 10px; left: 10px;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            box-shadow: 0 3px 10px rgba(0,0,0,0.15);
            display: inline-flex; align-items: center; gap: 5px;
            color: white;
        }
        .branch-admin-status.s-available    { background: rgba(40,167,69,0.95); }
        .branch-admin-status.s-unavailable  { background: rgba(231,76,60,0.95); }
        .branch-admin-status.s-maintenance  { background: rgba(243,156,18,0.95); }

        .branch-admin-body {
            padding: 1.1rem 1.2rem 1.3rem;
            display: flex; flex-direction: column; gap: 0.8rem;
            flex: 1;
        }
        .branch-admin-body h3 {
            margin: 0; font-size: 1.1rem; color: var(--primary-dark);
        }
        .branch-admin-meta {
            font-size: 0.82rem; color: #777; line-height: 1.5;
        }
        .branch-admin-meta i {
            color: var(--primary); width: 14px; margin-right: 5px;
        }

        .branch-admin-actions {
            display: flex; flex-direction: column; gap: 8px;
            margin-top: 4px;
            border-top: 1px dashed #e3e8ee;
            padding-top: 0.9rem;
        }
        .branch-admin-actions .row {
            display: flex; gap: 6px; align-items: center; flex-wrap: wrap;
        }
        .branch-admin-actions .label {
            font-size: 0.75rem; font-weight: 600; color: #888;
            text-transform: uppercase; letter-spacing: 0.04em;
            margin: 0; min-width: 64px;
        }
        .btn-mini {
            border: none; cursor: pointer;
            padding: 6px 13px; border-radius: 50px;
            font-size: 0.78rem; font-weight: 600;
            display: inline-flex; align-items: center; gap: 5px;
            transition: filter 0.2s, transform 0.15s;
            text-decoration: none;
            font-family: inherit;
        }
        .btn-mini:hover { filter: brightness(1.1); transform: translateY(-1px); }
        .btn-mini.btn-make-avail   { background: #28a745; color: white; }
        .btn-mini.btn-make-unavail { background: #e74c3c; color: white; }
        .btn-mini.btn-upload       { background: var(--primary); color: white; }
        .btn-mini.btn-replace      { background: rgba(0,119,182,0.12); color: var(--primary-dark); }
        .btn-mini.btn-delete-img   { background: rgba(231,76,60,0.1);  color: #c0392b; }
        .btn-mini.btn-delete-img.disabled {
            opacity: 0.5; pointer-events: none; cursor: not-allowed;
        }

        .file-input-hidden { display: none; }
        .branch-admin-card .file-name-preview {
            font-size: 0.78rem; color: #555;
            font-style: italic;
            display: block; word-break: break-all;
        }

        /* ─────────────────────────────────────────────────────────────
           GALLERY (admin-side branch gallery management)
           ───────────────────────────────────────────────────────────── */
        .btn-mini.btn-gallery {
            background: rgba(118,75,162,0.12);
            color: #5b3490;
        }
        .gallery-section {
            border-top: 1px dashed #e3e8ee;
            padding-top: 0.9rem;
            margin-top: 4px;
            display: none;             /* expanded by toggle */
        }
        .gallery-section.is-open { display: block; }
        .gallery-section-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 0.6rem;
        }
        .gallery-section-header .label {
            font-size: 0.75rem; font-weight: 700; color: #555;
            text-transform: uppercase; letter-spacing: 0.05em;
            margin: 0;
        }
        .gallery-section-header .count-pill {
            font-size: 0.72rem; font-weight: 700;
            padding: 3px 9px; border-radius: 50px;
            background: rgba(0,119,182,0.1); color: var(--primary-dark);
        }
        .gallery-section-header .count-pill.is-full {
            background: rgba(231,76,60,0.12); color: #b03a2e;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        .gallery-thumb {
            position: relative;
            width: 100%;
            aspect-ratio: 1 / 1;
            border-radius: 10px;
            overflow: hidden;
            background: #eef3f8;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: transform 0.18s, box-shadow 0.18s;
        }
        .gallery-thumb:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(0,119,182,0.18);
        }
        .gallery-thumb img {
            width: 100%; height: 100%;
            object-fit: cover; display: block;
        }
        .gallery-thumb .gt-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, transparent 0%, rgba(0,0,0,0.55) 75%);
            display: flex; align-items: flex-end; justify-content: center;
            gap: 6px;
            padding: 6px;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .gallery-thumb:hover .gt-overlay { opacity: 1; }
        .gallery-thumb .gt-btn {
            background: white;
            border: none;
            width: 28px; height: 28px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 0.8rem;
            color: var(--primary-dark);
            box-shadow: 0 3px 10px rgba(0,0,0,0.25);
            transition: transform 0.15s, background 0.15s, color 0.15s;
            font-family: inherit;
        }
        .gallery-thumb .gt-btn:hover { transform: scale(1.1); }
        .gallery-thumb .gt-btn.gt-delete:hover { background: #e74c3c; color: white; }
        .gallery-thumb .gt-btn.gt-replace:hover { background: var(--primary); color: white; }

        .gallery-tile-add {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 6px;
            border-radius: 10px;
            border: 2px dashed rgba(0,119,182,0.4);
            color: var(--primary-dark);
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            background: rgba(0,119,182,0.04);
            transition: background 0.2s, border-color 0.2s, transform 0.15s;
            aspect-ratio: 1 / 1;
        }
        .gallery-tile-add:hover {
            background: rgba(0,119,182,0.1);
            border-color: var(--primary);
            transform: translateY(-2px);
        }
        .gallery-tile-add i { font-size: 1.1rem; }
        .gallery-tile-add.is-disabled {
            opacity: 0.45; pointer-events: none;
            border-style: solid; background: #f5f7fa;
        }

        .gallery-empty-hint {
            padding: 14px 12px;
            background: rgba(0,119,182,0.05);
            border: 1px dashed rgba(0,119,182,0.25);
            border-radius: 10px;
            font-size: 0.82rem;
            color: #555;
            text-align: center;
            margin-bottom: 0.6rem;
        }

        @media (max-width: 600px) {
            .branch-admin-grid { grid-template-columns: 1fr; }
            .maint-toggle-card { flex-direction: column; align-items: flex-start; }
        }

        /* ─────────────────────────────────────────────────────────────
           CUSTOMER ROW ACTIONS (edit / delete inline buttons)
           ───────────────────────────────────────────────────────────── */
        .row-actions {
            display: inline-flex; gap: 6px; align-items: center;
        }
        .btn-row {
            border: none; cursor: pointer;
            padding: 6px 12px; border-radius: 50px;
            font-size: 0.78rem; font-weight: 600;
            display: inline-flex; align-items: center; gap: 5px;
            transition: filter 0.2s, transform 0.15s, box-shadow 0.2s;
            text-decoration: none; font-family: inherit;
        }
        .btn-row:hover { filter: brightness(1.1); transform: translateY(-1px); }
        .btn-row.btn-edit   { background: rgba(0,119,182,0.12); color: var(--primary-dark); }
        .btn-row.btn-edit:hover   { background: var(--primary); color: white; }
        .btn-row.btn-trash  { background: rgba(231,76,60,0.1); color: #c0392b; }
        .btn-row.btn-trash:hover  { background: #e74c3c; color: white; }
        .btn-row.btn-toggle-avail   { background: rgba(231,76,60,0.1); color: #c0392b; }
        .btn-row.btn-toggle-avail:hover { background: #e74c3c; color: white; }
        .btn-row.btn-toggle-unavail { background: rgba(40,167,69,0.12); color: #155724; }
        .btn-row.btn-toggle-unavail:hover { background: #28a745; color: white; }

        .modal textarea {
            width: 100%; padding: 11px 14px; border-radius: 10px;
            border: 2px solid #e3e8ee; font-size: 0.9rem;
            font-family: inherit; resize: vertical; min-height: 80px;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .modal textarea:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,119,182,0.12);
        }
        .modal select {
            width: 100%; padding: 11px 14px; border-radius: 10px;
            border: 2px solid #e3e8ee; font-size: 0.9rem;
            background: white; cursor: pointer; box-sizing: border-box;
            font-family: inherit;
        }
        .modal select:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,119,182,0.12);
        }

        /* ─────────────────────────────────────────────────────────────
           RESERVATIONS — compact Month/Year filter pill
           ───────────────────────────────────────────────────────────── */
        .res-date-filter {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 6px 4px 12px;
            background: rgba(0,119,182,0.06);
            border: 1.5px solid rgba(0,119,182,0.18);
            border-radius: 50px;
            margin-left: auto;
        }
        .res-date-filter .rd-label {
            font-size: 0.78rem; font-weight: 600; color: var(--primary-dark);
            display: inline-flex; align-items: center; gap: 5px;
        }
        .res-date-filter select {
            appearance: none; -webkit-appearance: none;
            background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24'%3E%3Cpath fill='%230077b6' d='M7 10l5 5 5-5z'/%3E%3C/svg%3E") no-repeat right 8px center;
            border: 1.5px solid rgba(0,119,182,0.2);
            border-radius: 50px;
            padding: 5px 24px 5px 11px;
            font-size: 0.78rem; font-weight: 600; color: #2c3e50;
            cursor: pointer; outline: none; font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .res-date-filter select:hover, .res-date-filter select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,119,182,0.12);
        }
        .res-date-filter button {
            background: var(--primary); color: white; border: none;
            padding: 5px 14px; border-radius: 50px; cursor: pointer;
            font-size: 0.78rem; font-weight: 600;
            display: inline-flex; align-items: center; gap: 5px;
            transition: filter 0.15s; font-family: inherit;
        }
        .res-date-filter button:hover { filter: brightness(1.1); }
        .res-date-filter .rd-reset {
            color: #999; text-decoration: none; font-size: 0.75rem;
            padding: 4px 9px; border-radius: 50px;
        }
        .res-date-filter .rd-reset:hover { color: #ef476f; background: rgba(239,71,111,0.07); }

        @media (max-width: 760px) {
            .res-date-filter { width: 100%; margin-left: 0; flex-wrap: wrap; }
        }

        /* ─────────────────────────────────────────────────────────────
           AMENITIES MANAGEMENT VIEW
           ───────────────────────────────────────────────────────────── */
        .am-branch-section {
            background: white;
            border-radius: 16px;
            padding: 1.4rem 1.6rem;
            box-shadow: 0 4px 18px rgba(0,0,0,0.07);
            margin-bottom: 1.5rem;
        }
        .am-branch-header {
            display: flex; align-items: center; gap: 12px;
            padding-bottom: 0.8rem; margin-bottom: 1rem;
            border-bottom: 2px dashed rgba(0,119,182,0.15);
        }
        .am-branch-header .am-branch-icon {
            width: 38px; height: 38px; border-radius: 10px;
            background: rgba(0,119,182,0.12); color: var(--primary-dark);
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; flex-shrink: 0;
        }
        .am-branch-header h3 {
            margin: 0; font-size: 1.05rem; color: var(--primary-dark);
        }
        .am-branch-header .am-count {
            margin-left: auto;
            font-size: 0.78rem; font-weight: 700;
            padding: 4px 11px; border-radius: 50px;
            background: rgba(0,119,182,0.1); color: var(--primary-dark);
        }

        .am-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 0.9rem;
        }
        .am-card {
            background: linear-gradient(135deg, rgba(0,119,182,0.04) 0%, rgba(102,126,234,0.04) 100%);
            border: 1px solid rgba(0,119,182,0.15);
            border-left: 4px solid var(--secondary);
            border-radius: 14px;
            padding: 1rem 1.1rem;
            display: flex; flex-direction: column; gap: 8px;
            transition: transform 0.18s, box-shadow 0.18s;
        }
        .am-card:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,119,182,0.12); }
        .am-card.is-unavail {
            background: linear-gradient(135deg, rgba(231,76,60,0.05) 0%, rgba(231,76,60,0.02) 100%);
            border-color: rgba(231,76,60,0.2);
            border-left-color: #e74c3c;
        }
        .am-card-head {
            display: flex; align-items: flex-start; gap: 10px;
            justify-content: space-between;
        }
        .am-card-head h4 {
            margin: 0; font-size: 0.98rem; color: #2c3e50;
            line-height: 1.3;
        }
        .am-status-pill {
            font-size: 0.7rem; font-weight: 700;
            padding: 3px 10px; border-radius: 50px;
            text-transform: uppercase; letter-spacing: 0.04em;
            white-space: nowrap;
        }
        .am-status-pill.s-on  { background: #d4edda; color: #155724; }
        .am-status-pill.s-off { background: #f8d7da; color: #721c24; }
        .am-card p {
            margin: 0; font-size: 0.83rem; color: #666; line-height: 1.5;
        }
        .am-card .am-actions {
            display: flex; gap: 6px; margin-top: auto;
            padding-top: 8px; border-top: 1px dashed rgba(0,0,0,0.07);
        }
        .am-empty {
            padding: 1rem; background: rgba(0,119,182,0.04);
            border: 1px dashed rgba(0,119,182,0.18);
            border-radius: 10px; color: #666;
            font-size: 0.85rem; text-align: center;
        }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div class="brand">
            <i class="fas fa-water" style="color: var(--secondary);"></i> CheckMates
        </div>
        <ul class="nav-links">
            <li>
                <a href="dashboard.php" class="<?= $view === 'dashboard' ? 'active' : '' ?>">
                    <i class="fas fa-home"></i> Reservations
                </a>
            </li>
            <li>
                <a href="dashboard.php?view=analytics" class="<?= $view === 'analytics' ? 'active' : '' ?>">
                    <i class="fas fa-chart-line"></i> Analytics
                </a>
            </li>
            <li>
                <a href="dashboard.php?view=feedback" class="<?= $view === 'feedback' ? 'active' : '' ?>">
                    <i class="fas fa-comment-dots"></i> Feedback
                </a>
            </li>
            <li>
                <a href="dashboard.php?view=branches" class="<?= $view === 'branches' ? 'active' : '' ?>">
                    <i class="fas fa-umbrella-beach"></i> Branches
                </a>
            </li>
            <li>
                <a href="dashboard.php?view=amenities" class="<?= $view === 'amenities' ? 'active' : '' ?>">
                    <i class="fas fa-swimming-pool"></i> Amenities
                </a>
            </li>
            <li>
                <a href="dashboard.php?view=customers" class="<?= $view === 'customers' ? 'active' : '' ?>">
                    <i class="fas fa-users"></i> Customers
                </a>
            </li>
            <li>
                <a href="dashboard.php?view=admins" class="<?= $view === 'admins' ? 'active' : '' ?>">
                    <i class="fas fa-user-shield"></i> Admins
                </a>
            </li>
            <li>
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </nav>

    <div class="main-content">

        <?php if ($action_msg): ?>
            <div class="alert success"><i class="fas fa-check-circle"></i><div><?= $action_msg ?></div></div>
        <?php endif; ?>
        <?php if ($action_err): ?>
            <div class="alert error"><i class="fas fa-exclamation-triangle"></i><div><?= $action_err ?></div></div>
        <?php endif; ?>

        <?php if ($view === 'analytics'): ?>
            <!-- ════════════════════════ ANALYTICS VIEW ════════════════════════ -->
            <div class="glass-panel">
                <div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:0.5rem;">
                    <div>
                        <h1 style="margin-bottom:0.3rem;"><?= $pageTitle ?></h1>
                        <p style="color:#7f8c8d; margin:0;">Booking and Performance Insights</p>
                    </div>
                </div>

                <!-- ────────── REDESIGNED DATE FILTER ────────── -->
                <?php
                    $month_names = ['January','February','March','April','May','June',
                                    'July','August','September','October','November','December'];
                    $year_start  = 2020;
                    $year_end    = $current_year;
                    $selected_label = $month_names[$filter_month - 1] . ' ' . $filter_year;

                    // Compute "last month" for the preset
                    $last_month_m = $current_month - 1; $last_month_y = $current_year;
                    if ($last_month_m < 1) { $last_month_m = 12; $last_month_y--; }

                    // Active-preset detection
                    $is_this_month  = ($filter_month === $current_month && $filter_year === $current_year);
                    $is_last_month  = ($filter_month === $last_month_m && $filter_year === $last_month_y);
                ?>

                <div class="analytics-filter-card">
                    <div class="af-header">
                        <div class="af-title">
                            <i class="fas fa-calendar-alt"></i> Filter Period
                        </div>
                        <span class="af-period-badge-new">
                            <i class="fas fa-clock"></i><?= $selected_label ?>
                        </span>
                    </div>

                    <!-- Quick Presets (January-year buttons removed; admins can still
                         pick any month/year via the Custom selector below) -->
                    <div class="af-presets">
                        <a href="dashboard.php?view=analytics&month=<?= $current_month ?>&year=<?= $current_year ?>"
                           class="af-preset <?= $is_this_month ? 'active' : '' ?>">
                            <i class="fas fa-calendar-day"></i> This Month
                        </a>
                        <a href="dashboard.php?view=analytics&month=<?= $last_month_m ?>&year=<?= $last_month_y ?>"
                           class="af-preset <?= $is_last_month ? 'active' : '' ?>">
                            <i class="fas fa-calendar-minus"></i> Last Month
                        </a>
                    </div>

                    <!-- Custom Selection Row -->
                    <form method="GET" action="dashboard.php" class="af-custom-row">
                        <input type="hidden" name="view" value="analytics">
                        <span class="af-custom-label">
                            <i class="fas fa-sliders-h" style="color:var(--primary);"></i> Custom:
                        </span>
                        <select name="month" class="af-select" aria-label="Month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $m === $filter_month ? 'selected' : '' ?>>
                                    <?= $month_names[$m - 1] ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <select name="year" class="af-select" aria-label="Year">
                            <?php for ($y = $year_end; $y >= $year_start; $y--): ?>
                                <option value="<?= $y ?>" <?= $y === $filter_year ? 'selected' : '' ?>>
                                    <?= $y ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <button type="submit" class="af-apply-btn">
                            <i class="fas fa-search"></i> Apply
                        </button>
                        <?php if (!$is_default_period): ?>
                            <a href="dashboard.php?view=analytics" class="af-reset-link">
                                <i class="fas fa-times-circle"></i> Reset
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid" style="margin-top:1.5rem;">
                    <div class="stat-card primary">
                        <h3>Pending Reservations</h3>
                        <div class="stat-value"><?= $stats['pending_reservations'] ?></div>
                        <div class="stat-label">Awaiting confirmation</div>
                    </div>
                    <div class="stat-card success">
                        <h3>Today's Bookings</h3>
                        <div class="stat-value"><?= $stats['todays_reservations'] ?></div>
                        <div class="stat-label">Confirmed for today</div>
                    </div>
                    <div class="stat-card info">
                        <h3><?= $selected_label ?></h3>
                        <div class="stat-value"><?= $stats['this_month_bookings'] ?></div>
                        <div class="stat-label">Total bookings</div>
                    </div>
                    <div class="stat-card">
                        <h3>Revenue — <?= $selected_label ?></h3>
                        <div class="stat-value">₱<?= number_format($stats['this_month_revenue'], 0) ?></div>
                        <div class="stat-label">Confirmed revenue</div>
                    </div>
                    <div class="stat-card">
                        <h3>Total Customers</h3>
                        <div class="stat-value"><?= $stats['total_customers'] ?></div>
                        <div class="stat-label">Registered users</div>
                    </div>
                    <div class="stat-card">
                        <h3>Avg Rating</h3>
                        <div class="stat-value"><?= number_format($stats['avg_rating'], 1) ?> ⭐</div>
                        <div class="stat-label"><?= $selected_label ?></div>
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid">
                <!-- Monthly Bookings Trend with YEAR NAVIGATION -->
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h2>Monthly Booking Trends — <?= $trend_year ?></h2>
                        <?php
                            $prev_year = $trend_year - 1;
                            $next_year = $trend_year + 1;
                            $can_prev  = ($prev_year >= 2020);
                            $can_next  = ($next_year <= $current_year + 1);

                            // Preserve other analytics params when changing trend year
                            $extra_qs = '&month=' . $filter_month . '&year=' . $filter_year;
                        ?>
                        <div class="trend-year-nav">
                            <a href="dashboard.php?view=analytics&trend_year=<?= $prev_year . $extra_qs ?>"
                               class="<?= !$can_prev ? 'disabled' : '' ?>"
                               title="Previous year">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <div class="trend-year-dropdown">
                                <button type="button" class="trend-year-current" onclick="toggleYearMenu(event)">
                                    <i class="fas fa-calendar-alt"></i> <?= $trend_year ?>
                                    <i class="fas fa-caret-down" style="font-size:0.7rem;"></i>
                                </button>
                                <div class="trend-year-menu" id="trendYearMenu">
                                    <?php foreach ($available_trend_years as $yopt): ?>
                                        <a href="dashboard.php?view=analytics&trend_year=<?= $yopt . $extra_qs ?>"
                                           class="<?= $yopt === $trend_year ? 'active' : '' ?>">
                                            <?= $yopt ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <a href="dashboard.php?view=analytics&trend_year=<?= $next_year . $extra_qs ?>"
                               class="<?= !$can_next ? 'disabled' : '' ?>"
                               title="Next year">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="monthlyBookingsChart"></canvas>
                    </div>
                </div>

                <!-- Reservation Type Distribution -->
                <div class="chart-card">
                    <h2>Reservation Type Distribution</h2>
                    <div class="chart-container">
                        <canvas id="reservationTypeChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Revenue Chart -->
            <div class="glass-panel">
                <div class="chart-card" style="box-shadow: none; padding: 0;">
                    <h2>Monthly Revenue Trend — <?= $trend_year ?></h2>
                    <div class="chart-container" style="height: 350px;">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Branch Performance -->
            <div class="glass-panel">
                <h2 style="margin-bottom: 1rem;">
                    Branch Performance
                    <span style="font-size:0.75rem;font-weight:500;color:var(--primary);background:rgba(0,119,182,0.1);padding:4px 12px;border-radius:50px;margin-left:10px;vertical-align:middle;"><?= $selected_label ?></span>
                </h2>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Branch Name</th>
                                <th>Total Reservations</th>
                                <th>Confirmed</th>
                                <th>Completed</th>
                                <th>Total Revenue</th>
                                <th>Avg Booking Value</th>
                                <th>Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($branch_stats as $branch): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($branch['branch_name']) ?></strong></td>
                                <td><?= $branch['total_reservations'] ?></td>
                                <td><?= $branch['confirmed_count'] ?></td>
                                <td><?= $branch['completed_count'] ?></td>
                                <td>₱<?= number_format($branch['total_revenue'], 2) ?></td>
                                <td>₱<?= number_format($branch['avg_revenue_per_booking'], 2) ?></td>
                                <td style="color: #f39c12;"><?= number_format($branch['avg_rating'], 1) ?> ⭐</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if(empty($branch_stats)): ?>
                        <p style="text-align:center; padding: 2rem; color: #888;">No branch data available.</p>
                    <?php endif; ?>
                </div>
            </div>

            <script>
                // Year-menu dropdown for trend chart
                function toggleYearMenu(e) {
                    e.stopPropagation();
                    document.getElementById('trendYearMenu').classList.toggle('open');
                }
                document.addEventListener('click', function() {
                    var m = document.getElementById('trendYearMenu');
                    if (m) m.classList.remove('open');
                });

                // Monthly Bookings Chart
                const monthlyCtx = document.getElementById('monthlyBookingsChart').getContext('2d');
                const monthlyData = <?= json_encode($monthly_data) ?>;

                new Chart(monthlyCtx, {
                    type: 'line',
                    data: {
                        labels: monthlyData.map(d => d.month_label),
                        datasets: [{
                            label: 'Total Bookings',
                            data: monthlyData.map(d => d.total_bookings),
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            tension: 0.4, fill: true, borderWidth: 3
                        }, {
                            label: 'Successful Bookings',
                            data: monthlyData.map(d => d.successful_bookings),
                            borderColor: '#f5576c',
                            backgroundColor: 'rgba(245, 87, 108, 0.1)',
                            tension: 0.4, fill: true, borderWidth: 3
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { position: 'top' } },
                        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                    }
                });

                // Reservation Type Chart
                const typeCtx = document.getElementById('reservationTypeChart').getContext('2d');
                const typeData = <?= json_encode($reservation_types) ?>;

                new Chart(typeCtx, {
                    type: 'doughnut',
                    data: {
                        labels: typeData.map(d => d.reservation_type),
                        datasets: [{
                            data: typeData.map(d => d.count),
                            backgroundColor: ['#667eea', '#f5576c', '#4ecdc4'],
                            borderWidth: 3, borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } }
                    }
                });

                // Revenue Chart
                const revenueCtx = document.getElementById('revenueChart').getContext('2d');
                new Chart(revenueCtx, {
                    type: 'bar',
                    data: {
                        labels: monthlyData.map(d => d.month_label),
                        datasets: [{
                            label: 'Revenue (₱)',
                            data: monthlyData.map(d => d.revenue),
                            backgroundColor: 'rgba(102, 126, 234, 0.8)',
                            borderColor: '#667eea',
                            borderWidth: 2, borderRadius: 8
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { callback: function(v) { return '₱' + v.toLocaleString(); } }
                            }
                        }
                    }
                });
            </script>

        <?php elseif ($view === 'feedback'): ?>
            <!-- ══════════════════════ FEEDBACK VIEW ══════════════════════ -->
            <div class="glass-panel">
                <h1><i style="color:var(--secondary)"></i><?= $pageTitle ?></h1>
                <p style="color:#7f8c8d;margin-bottom:1.8rem;">Customer satisfaction & reviews across all branches</p>

                <div class="feedback-summary">
                    <div class="fb-stat">
                        <div class="fb-icon blue"><i class="fas fa-comments"></i></div>
                        <div class="fb-text">
                            <strong><?= $fb_stats['total'] ?></strong>
                            <span>Total Reviews</span>
                        </div>
                    </div>
                    <div class="fb-stat">
                        <div class="fb-icon gold"><i class="fas fa-star"></i></div>
                        <div class="fb-text">
                            <strong><?= number_format($fb_stats['avg'], 1) ?></strong>
                            <span>Avg Rating</span>
                        </div>
                    </div>
                    <div class="fb-stat">
                        <div class="fb-icon green"><i class="fas fa-thumbs-up"></i></div>
                        <div class="fb-text">
                            <strong><?= $fb_stats['5star'] ?></strong>
                            <span>5-Star Reviews</span>
                        </div>
                    </div>
                    <div class="fb-stat">
                        <div class="fb-icon red"><i class="fas fa-thumbs-down"></i></div>
                        <div class="fb-text">
                            <strong><?= $fb_stats['1star'] ?></strong>
                            <span>1-Star Reviews</span>
                        </div>
                    </div>
                </div>

                <div class="filter-bar" style="margin-bottom:1.5rem;">
                    <span><i class="fas fa-star"></i> Filter by Rating:</span>
                    <?php
                    $rating_opts = ['All' => 'All Stars', '5' => '⭐⭐⭐⭐⭐', '4' => '⭐⭐⭐⭐', '3' => '⭐⭐⭐', '2' => '⭐⭐', '1' => '⭐'];
                    foreach ($rating_opts as $rv => $rl):
                        $is_active = ($filter_rating === $rv);
                        $rf_url    = 'dashboard.php?view=feedback' . ($rv !== 'All' ? '&rating=' . urlencode($rv) : '');
                    ?>
                        <a href="<?= $rf_url ?>" class="filter-btn f-rating <?= $is_active ? 'active' : '' ?>"><?= $rl ?></a>
                    <?php endforeach; ?>
                    <span style="font-size:0.82rem;color:#999;margin-left:4px;"><?= $fb_total ?> entr<?= $fb_total != 1 ? 'ies' : 'y' ?></span>
                </div>
            </div>

            <?php if (!empty($feedback_data)): ?>
            <div class="feedback-grid">
                <?php foreach ($feedback_data as $fb):
                    $stars      = (int)$fb['rating'];
                    $filled     = str_repeat('★', $stars);
                    $empty      = str_repeat('☆', 5 - $stars);
                    $star_class = $stars >= 4 ? 'high' : ($stars === 3 ? 'mid' : 'low');
                    $badge_cls  = 'r' . $stars;
                    $name       = htmlspecialchars($fb['full_name'] ?? 'Anonymous');
                    $initial    = strtoupper(substr($name, 0, 1));
                    $comment    = htmlspecialchars($fb['comments'] ?? '');
                    $branch     = htmlspecialchars($fb['branch_name'] ?? 'N/A');
                    $date       = $fb['feedback_date'] ? date('M d, Y', strtotime($fb['feedback_date'])) : '—';
                    $card_color = $stars >= 4 ? 'var(--primary)' : ($stars === 3 ? '#f39c12' : '#ef476f');
                ?>
                <div class="feedback-card" style="border-left-color:<?= $card_color ?>;">
                    <div class="fc-badge <?= $badge_cls ?>"><?= $stars ?></div>
                    <div class="fc-header">
                        <div class="fc-avatar"><?= $initial ?></div>
                        <div class="fc-meta">
                            <strong><?= $name ?></strong>
                            <span>#<?= $fb['feedback_id'] ?> &nbsp;·&nbsp; <?= $date ?></span>
                        </div>
                    </div>
                    <div class="fc-stars <?= $star_class ?>"><?= $filled ?><?= $empty ?></div>
                    <?php if ($comment): ?>
                    <div class="fc-comment">"<?= $comment ?>"</div>
                    <?php else: ?>
                    <div class="fc-comment" style="color:#bbb;font-style:normal;">No written comment.</div>
                    <?php endif; ?>
                    <div class="fc-footer">
                        <span class="fc-branch"><i class="fas fa-map-marker-alt" style="margin-right:4px;"></i><?= $branch ?></span>
                        <span><?= $date ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="glass-panel" style="text-align:center;padding:3rem;color:#888;">
                <i class="fas fa-comment-slash" style="font-size:2.5rem;margin-bottom:1rem;color:#ddd;display:block;"></i>
                No feedback entries found<?= $filter_rating !== 'All' ? ' for ' . $filter_rating . '-star rating.' : '.' ?>
            </div>
            <?php endif; ?>

            <?php if ($fb_pages > 1):
                $fb_sp = ($filter_rating !== 'All') ? '&rating=' . urlencode($filter_rating) : '';
            ?>
            <div class="pagination" style="margin-top:1.5rem;">
                <a href="dashboard.php?view=feedback&page=<?= $fb_page - 1 . $fb_sp ?>" class="page-btn <?= $fb_page <= 1 ? 'disabled' : '' ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php
                $win = 2; $s2 = max(1, $fb_page - $win); $e2 = min($fb_pages, $fb_page + $win);
                if ($s2 > 1): ?><a href="dashboard.php?view=feedback&page=1<?= $fb_sp ?>" class="page-btn">1</a><?php
                    if ($s2 > 2): ?><span style="padding:8px 4px;color:#aaa;">…</span><?php endif;
                endif;
                for ($p = $s2; $p <= $e2; $p++): ?>
                    <a href="dashboard.php?view=feedback&page=<?= $p . $fb_sp ?>" class="page-btn <?= $p === $fb_page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor;
                if ($e2 < $fb_pages):
                    if ($e2 < $fb_pages - 1): ?><span style="padding:8px 4px;color:#aaa;">…</span><?php endif; ?>
                    <a href="dashboard.php?view=feedback&page=<?= $fb_pages . $fb_sp ?>" class="page-btn"><?= $fb_pages ?></a>
                <?php endif; ?>
                <a href="dashboard.php?view=feedback&page=<?= $fb_page + 1 . $fb_sp ?>" class="page-btn <?= $fb_page >= $fb_pages ? 'disabled' : '' ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <span class="page-info">Page <?= $fb_page ?> of <?= $fb_pages ?></span>
            </div>
            <?php endif; ?>

        <?php elseif ($view === 'branches'): ?>
            <!-- ══════════════════════ BRANCHES MANAGEMENT VIEW ══════════════════════ -->
            <div class="glass-panel">
                <h1><i style="color:var(--secondary);"></i><?= $pageTitle ?></h1>
                <p style="color:#7f8c8d;margin-bottom:1.5rem;">
                    Toggle availability per branch, upload or replace branch photos, and place every
                    branch under maintenance with a single switch when needed.
                </p>

                <!-- Global maintenance toggle -->
                <form method="POST" action="dashboard.php?view=branches" id="maintForm" style="margin:0;">
                    <input type="hidden" name="action" value="set_global_maintenance">
                    <input type="hidden" name="state" id="maintStateInput" value="<?= $globalMaintenance === 1 ? 0 : 1 ?>">
                    <div class="maint-toggle-card <?= $globalMaintenance === 1 ? 'is-on' : '' ?>">
                        <div class="mt-icon">
                            <i class="fas <?= $globalMaintenance === 1 ? 'fa-tools' : 'fa-power-off' ?>"></i>
                        </div>
                        <div class="mt-text">
                            <strong>
                                <?= $globalMaintenance === 1
                                    ? 'All Branches Are Under Maintenance'
                                    : 'Site-Wide Maintenance: Off' ?>
                            </strong>
                            <span>
                                <?= $globalMaintenance === 1
                                    ? 'Online bookings are paused on every branch. Toggle off to resume normal operations.'
                                    : 'Turning this on immediately blocks new bookings on every branch &mdash; useful for resort-wide closures or system-wide events.' ?>
                            </span>
                        </div>
                        <label class="toggle-switch" title="Toggle global maintenance">
                            <input type="checkbox" id="maintToggle" <?= $globalMaintenance === 1 ? 'checked' : '' ?>
                                onchange="confirmMaintToggle(this)">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </form>

                <!-- Summary line -->
                <?php
                    $count_total = count($branches_data);
                    $count_avail = 0; $count_unavail = 0;
                    foreach ($branches_data as $b) {
                        if ((int)$b['is_available'] === 1) $count_avail++; else $count_unavail++;
                    }
                ?>
                <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:1.2rem;font-size:0.88rem;">
                    <span><i class="fas fa-building" style="color:var(--primary);margin-right:5px;"></i>
                        <strong style="color:var(--primary-dark);"><?= $count_total ?></strong>
                        branch<?= $count_total !== 1 ? 'es' : '' ?> total
                    </span>
                    <span><i class="fas fa-check-circle" style="color:#28a745;margin-right:5px;"></i>
                        <strong style="color:#155724;"><?= $count_avail ?></strong> available
                    </span>
                    <span><i class="fas fa-ban" style="color:#e74c3c;margin-right:5px;"></i>
                        <strong style="color:#721c24;"><?= $count_unavail ?></strong> unavailable
                    </span>
                </div>

                <!-- Branch cards -->
                <?php if (empty($branches_data)): ?>
                    <p style="text-align:center;padding:2rem;color:#888;">No branches found.</p>
                <?php else: ?>
                <div class="branch-admin-grid">
                    <?php foreach ($branches_data as $b):
                        $bAvail   = (int)$b['is_available'] === 1;
                        $blocked  = !$bAvail || $globalMaintenance === 1;
                        $hasImage = !empty($b['image_url']) && strpos($b['image_url'], 'assets/branches/') === 0;

                        if ($globalMaintenance === 1) {
                            $sCls='s-maintenance'; $sTxt='<i class="fas fa-tools"></i> Maintenance';
                        } elseif ($bAvail) {
                            $sCls='s-available'; $sTxt='<i class="fas fa-check-circle"></i> Available';
                        } else {
                            $sCls='s-unavailable'; $sTxt='<i class="fas fa-ban"></i> Unavailable';
                        }
                    ?>
                    <div class="branch-admin-card">
                        <div class="branch-admin-imgbox <?= $blocked ? 'is-blocked' : '' ?>">
                            <img src="../<?= htmlspecialchars($b['image_url']) ?>"
                                 alt="<?= htmlspecialchars($b['branch_name']) ?>"
                                 onerror="this.src='../assets/default.jpg'">
                            <span class="branch-admin-status <?= $sCls ?>"><?= $sTxt ?></span>
                        </div>
                        <div class="branch-admin-body">
                            <h3><?= htmlspecialchars($b['branch_name']) ?></h3>
                            <div class="branch-admin-meta">
                                <i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($b['location']) ?><br>
                                <i class="fas fa-clock"></i><?= htmlspecialchars($b['opening_hours'] ?? 'Always Open') ?>
                            </div>

                            <?php if (!$bAvail && !empty($b['unavailable_reason'])): ?>
                                <div style="background:rgba(231,76,60,0.06);border-left:3px solid #e74c3c;padding:8px 10px;border-radius:6px;font-size:0.8rem;color:#7d2a20;">
                                    <strong>Reason:</strong> <?= htmlspecialchars($b['unavailable_reason']) ?>
                                </div>
                            <?php endif; ?>

                            <div class="branch-admin-actions">
                                <!-- Availability toggle -->
                                <form method="POST" action="dashboard.php?view=branches" class="row"
                                      data-toggle-form="<?= $b['branch_id'] ?>"
                                      onsubmit="return confirmAvailabilityToggle(this, <?= (int)$bAvail ?>, '<?= htmlspecialchars(addslashes($b['branch_name'])) ?>');">
                                    <input type="hidden" name="action"    value="toggle_branch_availability">
                                    <input type="hidden" name="branch_id" value="<?= $b['branch_id'] ?>">
                                    <input type="hidden" name="new_state" value="<?= $bAvail ? 0 : 1 ?>">
                                    <input type="hidden" name="unavailable_reason" value="">
                                    <p class="label">Status</p>
                                    <?php if ($bAvail): ?>
                                        <button type="submit" class="btn-mini btn-make-unavail">
                                            <i class="fas fa-ban"></i> Mark Unavailable
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn-mini btn-make-avail">
                                            <i class="fas fa-check"></i> Mark Available
                                        </button>
                                    <?php endif; ?>
                                </form>

                                <!-- Image upload / replace + Gallery toggle -->
                                <form method="POST" action="dashboard.php?view=branches" class="row"
                                      enctype="multipart/form-data"
                                      onsubmit="return confirmImageUpload(this);">
                                    <input type="hidden" name="action"    value="upload_branch_image">
                                    <input type="hidden" name="branch_id" value="<?= $b['branch_id'] ?>">
                                    <p class="label">Image</p>
                                    <input type="file" name="branch_image" id="bf_<?= $b['branch_id'] ?>"
                                           class="file-input-hidden"
                                           accept=".jpg,.jpeg,.png,.webp,.gif"
                                           onchange="onBranchFileChosen(this, <?= $b['branch_id'] ?>)">
                                    <label for="bf_<?= $b['branch_id'] ?>" class="btn-mini <?= $hasImage ? 'btn-replace' : 'btn-upload' ?>">
                                        <i class="fas <?= $hasImage ? 'fa-sync-alt' : 'fa-upload' ?>"></i>
                                        <?= $hasImage ? 'Replace' : 'Upload' ?>
                                    </label>
                                    <button type="submit" class="btn-mini btn-upload"
                                            id="bs_<?= $b['branch_id'] ?>" style="display:none;">
                                        <i class="fas fa-save"></i> Save
                                    </button>
                                    <?php
                                        $galleryItems = $gallery_by_branch[(int)$b['branch_id']] ?? [];
                                        $galleryCount = count($galleryItems);
                                    ?>
                                    <button type="button"
                                            class="btn-mini btn-gallery"
                                            onclick="toggleGallery(<?= $b['branch_id'] ?>)"
                                            title="Manage gallery images (max <?= MAX_GALLERY_IMAGES ?>)">
                                        <i class="fas fa-images"></i>
                                        Gallery (<span id="gc_<?= $b['branch_id'] ?>"><?= $galleryCount ?></span>/<?= MAX_GALLERY_IMAGES ?>)
                                    </button>
                                    <span class="file-name-preview" id="bn_<?= $b['branch_id'] ?>"></span>
                                </form>

                                <!-- Delete cover image -->
                                <form method="POST" action="dashboard.php?view=branches" class="row"
                                      onsubmit="return confirm('Remove the uploaded cover image for <?= htmlspecialchars(addslashes($b['branch_name'])) ?>? The default placeholder will be shown instead.');">
                                    <input type="hidden" name="action"    value="delete_branch_image">
                                    <input type="hidden" name="branch_id" value="<?= $b['branch_id'] ?>">
                                    <p class="label">&nbsp;</p>
                                    <button type="submit" class="btn-mini btn-delete-img <?= $hasImage ? '' : 'disabled' ?>"
                                            <?= $hasImage ? '' : 'disabled' ?>
                                            title="<?= $hasImage ? 'Delete uploaded cover image' : 'No uploaded image to delete' ?>">
                                        <i class="fas fa-trash"></i> Delete Image
                                    </button>
                                </form>
                            </div>

                            <!-- ─── Gallery section (per branch) ─────────────────── -->
                            <?php
                                $galleryFull   = $galleryCount >= MAX_GALLERY_IMAGES;
                                $sectionOpenCls = ($galleryCount > 0) ? 'is-open' : '';
                            ?>
                            <div class="gallery-section <?= $sectionOpenCls ?>" id="gallery_<?= $b['branch_id'] ?>">
                                <div class="gallery-section-header">
                                    <p class="label"><i class="fas fa-images" style="color:#5b3490;margin-right:6px;"></i>Branch Gallery</p>
                                    <span class="count-pill <?= $galleryFull ? 'is-full' : '' ?>"
                                          id="gc2_<?= $b['branch_id'] ?>"><?= $galleryCount ?> / <?= MAX_GALLERY_IMAGES ?></span>
                                </div>

                                <?php if ($galleryCount === 0): ?>
                                    <div class="gallery-empty-hint">
                                        <i class="fas fa-info-circle" style="color:var(--primary);margin-right:5px;"></i>
                                        No gallery images yet. Click the tile below to upload up to <?= MAX_GALLERY_IMAGES ?> photos.
                                    </div>
                                <?php endif; ?>

                                <div class="gallery-grid">
                                    <?php foreach ($galleryItems as $g): ?>
                                        <div class="gallery-thumb">
                                            <img src="../<?= htmlspecialchars($g['image_path']) ?>"
                                                 alt="Gallery image"
                                                 loading="lazy"
                                                 onerror="this.src='../assets/default.jpg'">
                                            <div class="gt-overlay">
                                                <!-- Replace this gallery image -->
                                                <form method="POST" action="dashboard.php?view=branches"
                                                      enctype="multipart/form-data" style="margin:0;display:inline;">
                                                    <input type="hidden" name="action"    value="replace_gallery_image">
                                                    <input type="hidden" name="image_id"  value="<?= $g['image_id'] ?>">
                                                    <input type="hidden" name="branch_id" value="<?= $b['branch_id'] ?>">
                                                    <input type="file" name="gallery_image"
                                                           id="grp_<?= $g['image_id'] ?>"
                                                           class="file-input-hidden"
                                                           accept=".jpg,.jpeg,.png,.webp,.gif"
                                                           onchange="this.form.submit()">
                                                    <label for="grp_<?= $g['image_id'] ?>" class="gt-btn gt-replace"
                                                           title="Replace this image" style="cursor:pointer;">
                                                        <i class="fas fa-sync-alt"></i>
                                                    </label>
                                                </form>
                                                <!-- Delete this gallery image -->
                                                <form method="POST" action="dashboard.php?view=branches"
                                                      onsubmit="return confirm('Delete this gallery image? This cannot be undone.');"
                                                      style="margin:0;display:inline;">
                                                    <input type="hidden" name="action"    value="delete_gallery_image">
                                                    <input type="hidden" name="image_id"  value="<?= $g['image_id'] ?>">
                                                    <input type="hidden" name="branch_id" value="<?= $b['branch_id'] ?>">
                                                    <button type="submit" class="gt-btn gt-delete" title="Delete this image">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                    <!-- Add tile (multi-upload) -->
                                    <?php if (!$galleryFull): ?>
                                        <form method="POST" action="dashboard.php?view=branches"
                                              enctype="multipart/form-data" style="margin:0;">
                                            <input type="hidden" name="action"    value="upload_gallery_images">
                                            <input type="hidden" name="branch_id" value="<?= $b['branch_id'] ?>">
                                            <input type="file" name="gallery_images[]"
                                                   id="gadd_<?= $b['branch_id'] ?>"
                                                   class="file-input-hidden"
                                                   accept=".jpg,.jpeg,.png,.webp,.gif"
                                                   multiple
                                                   onchange="this.form.submit()">
                                            <label for="gadd_<?= $b['branch_id'] ?>" class="gallery-tile-add"
                                                   title="Upload up to <?= (MAX_GALLERY_IMAGES - $galleryCount) ?> more image(s)">
                                                <i class="fas fa-plus-circle"></i>
                                                <span>Add Photos</span>
                                                <small style="font-size:0.7rem;font-weight:500;opacity:0.75;">
                                                    <?= MAX_GALLERY_IMAGES - $galleryCount ?> slot<?= (MAX_GALLERY_IMAGES - $galleryCount) === 1 ? '' : 's' ?> left
                                                </small>
                                            </label>
                                        </form>
                                    <?php else: ?>
                                        <div class="gallery-tile-add is-disabled" title="Gallery is full — delete or replace existing images.">
                                            <i class="fas fa-check-circle"></i>
                                            <span>Full</span>
                                            <small style="font-size:0.7rem;font-weight:500;opacity:0.75;">9 / 9</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Reason modal (used when marking a branch unavailable) -->
            <div class="modal-overlay" id="reasonModal" onclick="if(event.target===this)closeModal('reasonModal')">
                <div class="modal">
                    <h2><i class="fas fa-info-circle"></i> Mark <span id="reasonBranchName"></span> Unavailable</h2>
                    <p class="modal-subtitle">Optionally describe why this branch is unavailable. Customers will see this reason on the branches page.</p>
                    <div class="form-group">
                        <label for="reasonInput">Reason (optional)</label>
                        <input type="text" id="reasonInput" maxlength="255"
                               placeholder="e.g. Facility renovation until June 30">
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-cancel" onclick="closeModal('reasonModal')">Cancel</button>
                        <button type="button" class="btn-submit" onclick="submitReasonForm()">
                            <i class="fas fa-ban"></i> Confirm
                        </button>
                    </div>
                </div>
            </div>

            <script>
                /* ─── Modal helpers reused across the dashboard ─── */
                function openModal(id)  { document.getElementById(id).classList.add('open'); document.body.style.overflow = 'hidden'; }
                function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
                        document.body.style.overflow = '';
                    }
                });

                /* ─── Maintenance toggle confirmation ─── */
                function confirmMaintToggle(checkbox) {
                    const turningOn = checkbox.checked;
                    const msg = turningOn
                        ? "Place ALL branches under maintenance? This immediately blocks new bookings everywhere on the site."
                        : "Lift maintenance and re-open all available branches for booking?";
                    if (!confirm(msg)) {
                        // Revert visual state
                        checkbox.checked = !turningOn;
                        return;
                    }
                    document.getElementById('maintStateInput').value = turningOn ? '1' : '0';
                    document.getElementById('maintForm').submit();
                }

                /* ─── Per-branch availability confirm + reason capture ─── */
                let _pendingAvailabilityForm = null;
                function confirmAvailabilityToggle(form, currentlyAvailable, branchName) {
                    if (currentlyAvailable === 1) {
                        // Going Available -> Unavailable: open the reason modal
                        _pendingAvailabilityForm = form;
                        document.getElementById('reasonBranchName').textContent = branchName;
                        document.getElementById('reasonInput').value = '';
                        openModal('reasonModal');
                        return false;  // stop default submit; modal will resubmit after Confirm
                    }
                    // Going Unavailable -> Available: simple confirm
                    return confirm("Mark " + branchName + " as available again?");
                }
                function submitReasonForm() {
                    if (!_pendingAvailabilityForm) return;
                    const reason = document.getElementById('reasonInput').value.trim();
                    _pendingAvailabilityForm.querySelector('input[name=unavailable_reason]').value = reason;
                    closeModal('reasonModal');
                    _pendingAvailabilityForm.submit();
                }

                /* ─── Image upload UX ─── */
                function onBranchFileChosen(input, branchId) {
                    const saveBtn = document.getElementById('bs_' + branchId);
                    const nameEl  = document.getElementById('bn_' + branchId);
                    if (input.files && input.files[0]) {
                        nameEl.textContent  = '\u2192 ' + input.files[0].name;
                        saveBtn.style.display = 'inline-flex';
                    } else {
                        nameEl.textContent  = '';
                        saveBtn.style.display = 'none';
                    }
                }
                function confirmImageUpload(form) {
                    const f = form.querySelector('input[type=file]');
                    if (!f.files || !f.files[0]) {
                        alert("Please choose an image first."); return false;
                    }
                    return true;
                }

                /* ─── Gallery: toggle the per-branch panel open/closed ─── */
                function toggleGallery(branchId) {
                    const sec = document.getElementById('gallery_' + branchId);
                    if (!sec) return;
                    sec.classList.toggle('is-open');
                    if (sec.classList.contains('is-open')) {
                        sec.scrollIntoView({behavior: 'smooth', block: 'nearest'});
                    }
                }
            </script>

        <?php elseif ($view === 'amenities'): ?>
            <!-- ══════════════════════ AMENITIES MANAGEMENT VIEW ══════════════════════ -->
            <div class="glass-panel">
                <h1><i class="fas fa-swimming-pool" style="color:var(--secondary);margin-right:10px;"></i><?= $pageTitle ?></h1>
                <p style="color:#7f8c8d;margin-bottom:1.5rem;">
                    Add new amenities, mark them as unavailable when undergoing maintenance,
                    or remove them entirely. All changes are reflected immediately on the
                    customer-facing Amenities page.
                </p>

                <div class="admin-toolbar">
                    <span style="font-size:0.85rem;color:#777;">
                        <strong style="color:var(--primary-dark);"><?= count($amenities_data) ?></strong>
                        amenit<?= count($amenities_data) !== 1 ? 'ies' : 'y' ?> across
                        <strong style="color:var(--primary-dark);"><?= count($amenity_branches) ?></strong>
                        branch<?= count($amenity_branches) !== 1 ? 'es' : '' ?>
                    </span>
                    <div class="toolbar-actions">
                        <button type="button" class="btn-action" onclick="openModal('addAmenityModal')">
                            <i class="fas fa-plus"></i> Add Amenity
                        </button>
                    </div>
                </div>

                <?php if (empty($amenity_branches)): ?>
                    <div class="am-empty">
                        <i class="fas fa-info-circle"></i>
                        No branches found. Add a branch first before creating amenities.
                    </div>
                <?php else: ?>
                    <?php foreach ($amenity_branches as $br):
                        $b_id    = (int)$br['branch_id'];
                        $b_items = $amenities_by_branch[$b_id] ?? [];
                    ?>
                    <div class="am-branch-section">
                        <div class="am-branch-header">
                            <div class="am-branch-icon"><i class="fas fa-building"></i></div>
                            <h3><?= htmlspecialchars($br['branch_name']) ?></h3>
                            <span class="am-count"><?= count($b_items) ?> amenit<?= count($b_items) !== 1 ? 'ies' : 'y' ?></span>
                        </div>

                        <?php if (empty($b_items)): ?>
                            <div class="am-empty">
                                <i class="fas fa-info-circle" style="color:var(--primary);margin-right:5px;"></i>
                                No amenities yet for this branch. Click <strong>Add Amenity</strong> above
                                and choose this branch.
                            </div>
                        <?php else: ?>
                        <div class="am-grid">
                            <?php foreach ($b_items as $am):
                                $is_avail = ($am['availability'] === 'Available');
                            ?>
                            <div class="am-card <?= $is_avail ? '' : 'is-unavail' ?>">
                                <div class="am-card-head">
                                    <h4><?= htmlspecialchars($am['amenity_name']) ?></h4>
                                    <span class="am-status-pill <?= $is_avail ? 's-on' : 's-off' ?>">
                                        <?= $is_avail ? 'Available' : 'Unavailable' ?>
                                    </span>
                                </div>
                                <p><?= !empty($am['description']) ? htmlspecialchars($am['description']) : '<em style="color:#999;">No description.</em>' ?></p>

                                <div class="am-actions">
                                    <!-- Toggle availability -->
                                    <form method="POST" action="dashboard.php?view=amenities" style="margin:0;">
                                        <input type="hidden" name="action" value="toggle_amenity_availability">
                                        <input type="hidden" name="amenity_id" value="<?= $am['amenity_id'] ?>">
                                        <?php if ($is_avail): ?>
                                            <button type="submit" class="btn-row btn-toggle-avail"
                                                    onclick="return confirm('Mark <?= htmlspecialchars(addslashes($am['amenity_name'])) ?> as Unavailable?');">
                                                <i class="fas fa-ban"></i> Mark Unavailable
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="btn-row btn-toggle-unavail">
                                                <i class="fas fa-check"></i> Mark Available
                                            </button>
                                        <?php endif; ?>
                                    </form>

                                    <!-- Delete amenity -->
                                    <form method="POST" action="dashboard.php?view=amenities" style="margin:0;"
                                          onsubmit="return confirm('Permanently delete the amenity \'<?= htmlspecialchars(addslashes($am['amenity_name'])) ?>\'? This cannot be undone.');">
                                        <input type="hidden" name="action" value="delete_amenity">
                                        <input type="hidden" name="amenity_id" value="<?= $am['amenity_id'] ?>">
                                        <button type="submit" class="btn-row btn-trash">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Add Amenity Modal -->
            <div class="modal-overlay" id="addAmenityModal" onclick="if(event.target===this)closeModal('addAmenityModal')">
                <div class="modal">
                    <h2><i class="fas fa-plus-circle"></i> Add New Amenity</h2>
                    <p class="modal-subtitle">Create a new amenity record. It will appear on the customer-facing Amenities page right away.</p>

                    <form method="POST" action="dashboard.php?view=amenities">
                        <input type="hidden" name="action" value="add_amenity">
                        <div class="form-group">
                            <label for="aa_branch">Branch</label>
                            <select id="aa_branch" name="branch_id" required>
                                <option value="">— Select a branch —</option>
                                <?php foreach ($amenity_branches as $br): ?>
                                    <option value="<?= $br['branch_id'] ?>"><?= htmlspecialchars($br['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="aa_name">Amenity Name</label>
                            <input type="text" id="aa_name" name="amenity_name" required maxlength="100"
                                   placeholder="e.g. Swimming Pool">
                        </div>
                        <div class="form-group">
                            <label for="aa_desc">Description (optional)</label>
                            <textarea id="aa_desc" name="description" maxlength="500"
                                      placeholder="A short note customers will see (max 500 chars)."></textarea>
                        </div>
                        <div class="form-group">
                            <label for="aa_avail">Availability</label>
                            <select id="aa_avail" name="availability">
                                <option value="Available" selected>Available</option>
                                <option value="Unavailable">Unavailable</option>
                            </select>
                        </div>
                        <div class="modal-actions">
                            <button type="button" class="btn-cancel" onclick="closeModal('addAmenityModal')">Cancel</button>
                            <button type="submit" class="btn-submit">
                                <i class="fas fa-plus"></i> Create Amenity
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
                function openModal(id) {
                    document.getElementById(id).classList.add('open');
                    document.body.style.overflow = 'hidden';
                }
                function closeModal(id) {
                    document.getElementById(id).classList.remove('open');
                    document.body.style.overflow = '';
                }
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
                        document.body.style.overflow = '';
                    }
                });
            </script>

        <?php elseif ($view === 'customers'): ?>
            <!-- ══════════════════════ CUSTOMERS VIEW ══════════════════════ -->
            <div class="glass-panel">
                <h1><?= $pageTitle ?></h1>

                <div class="customers-toolbar">
                    <form method="GET" action="dashboard.php" style="margin:0;">
                        <input type="hidden" name="view" value="customers">
                        <div class="search-box">
                            <i class="fas fa-search" aria-hidden="true"></i>
                            <input type="search" name="q"
                                   placeholder="Search by name, email or contact number…"
                                   value="<?= htmlspecialchars($cust_search) ?>"
                                   aria-label="Search customers"
                                   autocomplete="off">
                            <button type="submit">Search</button>
                            <?php if ($cust_search !== ''): ?>
                                <a href="dashboard.php?view=customers" class="search-clear" title="Clear search">
                                    <i class="fas fa-times"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="font-size:0.85rem;color:#777;">
                            <strong style="color:var(--primary-dark);"><?= $cust_total ?></strong>
                            customer<?= $cust_total !== 1 ? 's' : '' ?>
                        </span>
                        <button type="button" class="btn-action" onclick="openModal('addCustomerModal')">
                            <i class="fas fa-user-plus"></i> Add Customer
                        </button>
                        <a href="export_customers.php<?= $cust_search !== '' ? '?q=' . urlencode($cust_search) : '' ?>"
                           class="export-btn" title="Download CSV">
                            <i class="fas fa-file-export"></i> Export CSV
                        </a>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Contact</th>
                                <th>Registered</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($data as $row): ?>
                            <tr>
                                <td>#<?= $row['customer_id'] ?></td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <div style="width:30px; height:30px; background:var(--secondary); border-radius:50%; display:flex; align-items:center; justify-content:center; color:white; font-size:0.8rem;">
                                            <?= strtoupper(substr($row['full_name'], 0, 1)) ?>
                                        </div>
                                        <?= htmlspecialchars($row['full_name']) ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($row['email']) ?></td>
                                <td><?= htmlspecialchars($row['contact_number'] ?? '—') ?></td>
                                <td style="color:#888;font-size:0.85rem;">
                                    <?= !empty($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : '—' ?>
                                </td>
                                <td style="text-align:right;">
                                    <div class="row-actions">
                                        <button type="button" class="btn-row btn-edit"
                                                onclick='openEditCustomer(<?= json_encode([
                                                    "id"      => (int)$row["customer_id"],
                                                    "name"    => $row["full_name"],
                                                    "email"   => $row["email"],
                                                    "contact" => $row["contact_number"] ?? "",
                                                ], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'>
                                            <i class="fas fa-pen"></i> Edit
                                        </button>
                                        <form method="POST" action="dashboard.php?view=customers<?= $cust_search !== '' ? '&q=' . urlencode($cust_search) : '' ?>"
                                              style="display:inline;margin:0;"
                                              onsubmit="return confirm('Permanently delete customer \'<?= htmlspecialchars(addslashes($row['full_name'])) ?>\'? Their reservations and feedback will also be removed. This cannot be undone.');">
                                            <input type="hidden" name="action" value="delete_customer">
                                            <input type="hidden" name="customer_id" value="<?= $row['customer_id'] ?>">
                                            <button type="submit" class="btn-row btn-trash">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if(empty($data)): ?>
                        <p style="text-align:center; padding: 2rem; color: #888;">
                            <?php if ($cust_search !== ''): ?>
                                <i class="fas fa-search" style="margin-right:6px;color:#bbb;"></i>
                                No customer found matching
                                &ldquo;<strong><?= htmlspecialchars($cust_search) ?></strong>&rdquo;.
                            <?php else: ?>
                                No customers found.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Customer Pagination -->
                <?php if ($cust_pages > 1):
                    $cs_sp = ($cust_search !== '') ? '&q=' . urlencode($cust_search) : '';
                ?>
                <div class="pagination">
                    <a href="dashboard.php?view=customers&page=<?= $cust_page - 1 . $cs_sp ?>" class="page-btn <?= $cust_page <= 1 ? 'disabled' : '' ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <?php
                    $cwin = 2; $cs2 = max(1, $cust_page - $cwin); $ce2 = min($cust_pages, $cust_page + $cwin);
                    if ($cs2 > 1): ?><a href="dashboard.php?view=customers&page=1<?= $cs_sp ?>" class="page-btn">1</a><?php
                        if ($cs2 > 2): ?><span style="padding:8px 4px;color:#aaa;">…</span><?php endif;
                    endif;
                    for ($p = $cs2; $p <= $ce2; $p++): ?>
                        <a href="dashboard.php?view=customers&page=<?= $p . $cs_sp ?>" class="page-btn <?= $p === $cust_page ? 'active' : '' ?>"><?= $p ?></a>
                    <?php endfor;
                    if ($ce2 < $cust_pages):
                        if ($ce2 < $cust_pages - 1): ?><span style="padding:8px 4px;color:#aaa;">…</span><?php endif; ?>
                        <a href="dashboard.php?view=customers&page=<?= $cust_pages . $cs_sp ?>" class="page-btn"><?= $cust_pages ?></a>
                    <?php endif; ?>
                    <a href="dashboard.php?view=customers&page=<?= $cust_page + 1 . $cs_sp ?>" class="page-btn <?= $cust_page >= $cust_pages ? 'disabled' : '' ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                    <span class="page-info">Page <?= $cust_page ?> of <?= $cust_pages ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Add Customer Modal -->
            <div class="modal-overlay" id="addCustomerModal" onclick="if(event.target===this)closeModal('addCustomerModal')">
                <div class="modal">
                    <h2><i class="fas fa-user-plus"></i> Add New Customer</h2>
                    <p class="modal-subtitle">Create a new customer record. Customers added here can later sign up using the same email to claim their account.</p>

                    <form method="POST" action="dashboard.php?view=customers">
                        <input type="hidden" name="action" value="add_customer">
                        <div class="form-group">
                            <label for="ac_full_name">Full Name</label>
                            <input type="text" id="ac_full_name" name="full_name" required maxlength="255"
                                   placeholder="e.g. Juan Dela Cruz">
                        </div>
                        <div class="form-group">
                            <label for="ac_email">Email</label>
                            <input type="email" id="ac_email" name="email" required maxlength="100"
                                   placeholder="e.g. juan@example.com">
                        </div>
                        <div class="form-group">
                            <label for="ac_contact">Contact Number</label>
                            <input type="text" id="ac_contact" name="contact_number" maxlength="15"
                                   placeholder="e.g. 09171234567">
                        </div>
                        <div class="modal-actions">
                            <button type="button" class="btn-cancel" onclick="closeModal('addCustomerModal')">Cancel</button>
                            <button type="submit" class="btn-submit">
                                <i class="fas fa-plus"></i> Create Customer
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Customer Modal -->
            <div class="modal-overlay" id="editCustomerModal" onclick="if(event.target===this)closeModal('editCustomerModal')">
                <div class="modal">
                    <h2><i class="fas fa-user-edit"></i> Edit Customer</h2>
                    <p class="modal-subtitle">Update this customer's contact details. Changing the email also updates their login (if any).</p>

                    <form method="POST" action="dashboard.php?view=customers<?= $cust_search !== '' ? '&q=' . urlencode($cust_search) : '' ?>">
                        <input type="hidden" name="action" value="edit_customer">
                        <input type="hidden" name="customer_id" id="ec_id">
                        <div class="form-group">
                            <label for="ec_full_name">Full Name</label>
                            <input type="text" id="ec_full_name" name="full_name" required maxlength="255">
                        </div>
                        <div class="form-group">
                            <label for="ec_email">Email</label>
                            <input type="email" id="ec_email" name="email" required maxlength="100">
                        </div>
                        <div class="form-group">
                            <label for="ec_contact">Contact Number</label>
                            <input type="text" id="ec_contact" name="contact_number" maxlength="15">
                        </div>
                        <div class="modal-actions">
                            <button type="button" class="btn-cancel" onclick="closeModal('editCustomerModal')">Cancel</button>
                            <button type="submit" class="btn-submit">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
                function openModal(id) {
                    document.getElementById(id).classList.add('open');
                    document.body.style.overflow = 'hidden';
                }
                function closeModal(id) {
                    document.getElementById(id).classList.remove('open');
                    document.body.style.overflow = '';
                }
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
                        document.body.style.overflow = '';
                    }
                });
                function openEditCustomer(c) {
                    document.getElementById('ec_id').value        = c.id;
                    document.getElementById('ec_full_name').value = c.name || '';
                    document.getElementById('ec_email').value     = c.email || '';
                    document.getElementById('ec_contact').value   = c.contact || '';
                    openModal('editCustomerModal');
                }
            </script>

        <?php elseif ($view === 'admins'): ?>
            <!-- ══════════════════════ ADMIN MANAGEMENT VIEW ══════════════════════ -->
            <div class="glass-panel">
                <h1><i class="fas fa-user-shield" style="color:var(--secondary);margin-right:10px;"></i><?= $pageTitle ?></h1>
                <p style="color:#7f8c8d;margin-bottom:1.5rem;">
                    Manage administrator accounts.
                    <?php if ($is_super_admin): ?>
                        You are signed in as the <strong style="color:#b06d00;">Main Administrator</strong> and can add or remove admins.
                    <?php else: ?>
                        You can add new admins and change your own password. Only the Main Administrator can remove accounts.
                    <?php endif; ?>
                </p>

                <div class="admin-toolbar">
                    <span style="font-size:0.85rem;color:#777;">
                        <strong style="color:var(--primary-dark);"><?= count($admins) ?></strong>
                        admin account<?= count($admins) !== 1 ? 's' : '' ?>
                    </span>
                    <div class="toolbar-actions">
                        <button type="button" onclick="openModal('changePwModal')">
                            <i></i>Change My Password
                        </button>
                        <button type="button" class="btn-action" onclick="openModal('addAdminModal')">
                            <i></i> Add Admin
                        </button>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email (Username)</th>
                                <th>Role</th>
                                <th>Created</th>
                                <th>Created By</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admins as $a):
                                $is_self  = ((int)$a['user_id'] === (int)$current_user_id);
                                $is_super = (int)$a['is_super_admin'] === 1;
                                $initial  = strtoupper(substr($a['full_name'], 0, 1));
                            ?>
                            <tr>
                                <td>#<?= $a['admin_id'] ?></td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <div style="width:32px; height:32px; background:<?= $is_super ? 'linear-gradient(135deg,#f6d365,#fda085)' : 'var(--primary)' ?>; border-radius:50%; display:flex; align-items:center; justify-content:center; color:white; font-size:0.85rem; font-weight:700;">
                                            <?= htmlspecialchars($initial) ?>
                                        </div>
                                        <div>
                                            <div style="font-weight:600;"><?= htmlspecialchars($a['full_name']) ?>
                                                <?php if ($is_self): ?>
                                                    <span style="font-size:0.7rem;color:#888;font-weight:500;">(you)</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($a['must_change_password']) && !$is_super): ?>
                                                <span class="badge-pill badge-warn" style="margin-top:3px;">
                                                    <i class="fas fa-exclamation-circle"></i> Default password
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($a['username']) ?></td>
                                <td>
                                    <?php if ($is_super): ?>
                                        <span class="badge-pill badge-super"></i> Main Admin</span>
                                    <?php else: ?>
                                        <span class="badge-pill badge-admin"></i> Admin</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:#888;font-size:0.85rem;">
                                    <?= date('M d, Y', strtotime($a['created_at'])) ?>
                                </td>
                                <td style="color:#888;font-size:0.85rem;">
                                    <?= htmlspecialchars($a['created_by_name'] ?? '—') ?>
                                </td>
                                <td style="text-align:right;">
                                    <?php if ($is_super_admin && !$is_super && !$is_self): ?>
                                        <form method="POST" action="dashboard.php?view=admins" style="display:inline;"
                                              onsubmit="return confirm('Permanently delete admin \'<?= htmlspecialchars(addslashes($a['full_name'])) ?>\'? This cannot be undone.');">
                                            <input type="hidden" name="action" value="delete_admin">
                                            <input type="hidden" name="admin_id" value="<?= $a['admin_id'] ?>">
                                            <button type="submit" class="btn-action btn-reject">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    <?php elseif ($is_super): ?>
                                        <span style="color:#aaa;font-size:0.82rem;font-style:italic;">Protected</span>
                                    <?php elseif ($is_self): ?>
                                        <span style="color:#aaa;font-size:0.82rem;font-style:italic;">—</span>
                                    <?php else: ?>
                                        <span style="color:#aaa;font-size:0.82rem;font-style:italic;" title="Only the Main Administrator can delete admins">
                                            <i class="fas fa-lock"></i> Restricted
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add Admin Modal -->
            <div class="modal-overlay" id="addAdminModal" onclick="if(event.target===this)closeModal('addAdminModal')">
                <div class="modal">
                    <h2><i class="fas fa-user-plus"></i> Add New Admin</h2>
                    <p class="modal-subtitle">Create a new administrator account.</p>

                    <div class="modal-info">
                        <i class="fas fa-info-circle" style="color:var(--primary);margin-right:6px;"></i>
                        The new admin's default password will be <strong>ChangeMe@123</strong>.
                        They can update it from this page after first login.
                    </div>

                    <form method="POST" action="dashboard.php?view=admins">
                        <input type="hidden" name="action" value="add_admin">
                        <div class="form-group">
                            <label for="add_full_name">Full Name</label>
                            <input type="text" id="add_full_name" name="full_name" required maxlength="100"
                                   placeholder="e.g. Juan Dela Cruz">
                        </div>
                        <div class="form-group">
                            <label for="add_email">Email (Username)</label>
                            <input type="email" id="add_email" name="email" required maxlength="100"
                                   placeholder="e.g. juan.delacruz@checkmates.local">
                        </div>
                        <div class="modal-actions">
                            <button type="button" class="btn-cancel" onclick="closeModal('addAdminModal')">Cancel</button>
                            <button type="submit" class="btn-submit">
                                <i></i>Create Admin
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Change Password Modal -->
            <div class="modal-overlay" id="changePwModal" onclick="if(event.target===this)closeModal('changePwModal')">
                <div class="modal">
                    <h2><i></i>Change My Password</h2>
                    <p class="modal-subtitle">Update the password for <strong><?= htmlspecialchars($current_admin['username']) ?></strong>.</p>

                    <form method="POST" action="dashboard.php?view=admins">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-group">
                            <label for="cp_current">Current Password</label>
                            <div class="password-field">
                                <input type="password" id="cp_current" name="current_password"
                                       required autocomplete="current-password">
                                <button type="button" class="password-toggle"
                                        data-target="cp_current"
                                        aria-label="Show password" aria-pressed="false"
                                        title="Show password">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="cp_new">New Password</label>
                            <div class="password-field">
                                <input type="password" id="cp_new" name="new_password"
                                       required minlength="8" autocomplete="new-password">
                                <button type="button" class="password-toggle"
                                        data-target="cp_new"
                                        aria-label="Show password" aria-pressed="false"
                                        title="Show password">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="cp_confirm">Confirm New Password</label>
                            <div class="password-field">
                                <input type="password" id="cp_confirm" name="confirm_password"
                                       required minlength="8" autocomplete="new-password">
                                <button type="button" class="password-toggle"
                                        data-target="cp_confirm"
                                        aria-label="Show password" aria-pressed="false"
                                        title="Show password">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="modal-info" style="margin-top:0.6rem;">
                            <i style="color:var(--primary);margin-right:0px;"></i>
                        Use at least 8 characters. Mix letters, numbers and symbols for stronger security.
                        </div>
                        <div class="modal-actions">
                            <button type="button" class="btn-cancel" onclick="closeModal('changePwModal')">Cancel</button>
                            <button type="submit" class="btn-submit">
                                <i></i>Update Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
                function openModal(id) {
                    document.getElementById(id).classList.add('open');
                    document.body.style.overflow = 'hidden';
                }
                function closeModal(id) {
                    document.getElementById(id).classList.remove('open');
                    document.body.style.overflow = '';
                }
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
                        document.body.style.overflow = '';
                    }
                });

                /* ─────────────────────────────────────────────────────
                   Show / Hide password toggle
                   ─────────────────────────────────────────────────────
                   Each .password-toggle button carries data-target="<input id>".
                   Clicking flips the input's `type` between "password" and
                   "text", swaps the eye / eye-slash icon, and updates the
                   ARIA state for screen readers. No page refresh, no impact
                   on form submission — the value is still hashed server-side
                   via password_hash().                                       */
                document.querySelectorAll('.password-toggle').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var input = document.getElementById(btn.dataset.target);
                        if (!input) return;
                        var icon = btn.querySelector('i');
                        var showing = input.type === 'text';

                        if (showing) {
                            input.type = 'password';
                            if (icon) { icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
                            btn.setAttribute('aria-pressed', 'false');
                            btn.setAttribute('aria-label', 'Show password');
                            btn.setAttribute('title', 'Show password');
                        } else {
                            input.type = 'text';
                            if (icon) { icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
                            btn.setAttribute('aria-pressed', 'true');
                            btn.setAttribute('aria-label', 'Hide password');
                            btn.setAttribute('title', 'Hide password');
                        }
                    });
                });

                /* When the Change Password modal is closed, reset every
                   password field back to hidden so the next open is clean. */
                (function () {
                    var modal = document.getElementById('changePwModal');
                    if (!modal) return;
                    var observer = new MutationObserver(function () {
                        if (!modal.classList.contains('open')) {
                            modal.querySelectorAll('.password-field input').forEach(function (inp) {
                                inp.type = 'password';
                            });
                            modal.querySelectorAll('.password-toggle').forEach(function (btn) {
                                var icon = btn.querySelector('i');
                                if (icon) { icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
                                btn.setAttribute('aria-pressed', 'false');
                                btn.setAttribute('aria-label', 'Show password');
                                btn.setAttribute('title', 'Show password');
                            });
                        }
                    });
                    observer.observe(modal, { attributes: true, attributeFilter: ['class'] });
                })();
            </script>

        <?php else: ?>
            <!-- ══════════════════════ RESERVATIONS VIEW ══════════════════════ -->
            <div class="glass-panel">
                <h1><?= $pageTitle ?></h1>

                <?php
                    $statuses = ['All','Pending','Confirmed','Completed','Cancelled'];
                    $filter_classes = ['All'=>'','Pending'=>'f-pending','Confirmed'=>'f-confirmed','Completed'=>'f-completed','Cancelled'=>'f-cancelled'];
                    $month_names_full = ['January','February','March','April','May','June',
                                         'July','August','September','October','November','December'];
                    $has_date_filter = ($allowed_res_month !== null || $allowed_res_year !== null);

                    // Persist current status across the date-filter form so admins
                    // don't lose their place when switching months.
                    $persist_status_qs = ($filter_status !== 'All') ? '&status=' . urlencode($filter_status) : '';
                ?>
                <div class="filter-bar">
                    <span><i class="fas fa-filter"></i> Filter:</span>
                    <?php foreach ($statuses as $s):
                        $is_active = ($filter_status === $s);
                        // Preserve the current month/year filter while switching status.
                        $url_qs = [];
                        if ($s !== 'All')                  $url_qs[] = 'status=' . urlencode($s);
                        if ($allowed_res_month !== null)   $url_qs[] = 'res_month=' . $allowed_res_month;
                        if ($allowed_res_year  !== null)   $url_qs[] = 'res_year='  . $allowed_res_year;
                        $url = 'dashboard.php' . (count($url_qs) ? '?' . implode('&', $url_qs) : '');

                        // Per-status counter respects the active month/year filter.
                        $cnt = null;
                        if ($s !== 'All') {
                            $cnt_where = "WHERE r.status = " . $pdo->quote($s)
                                       . " AND NOT (r.status = 'Pending' AND r.payment_status = 'Unpaid')";
                            if ($allowed_res_month !== null) $cnt_where .= " AND MONTH(r.reservation_date) = " . (int)$allowed_res_month;
                            if ($allowed_res_year  !== null) $cnt_where .= " AND YEAR(r.reservation_date)  = " . (int)$allowed_res_year;
                            $cnt = $pdo->query("SELECT COUNT(*) FROM reservations r $cnt_where")->fetchColumn();
                        }
                    ?>
                        <a href="<?= $url ?>" class="filter-btn <?= $filter_classes[$s] ?> <?= $is_active ? 'active' : '' ?>">
                            <?= $s ?><?php if($cnt !== null) echo " <span style='opacity:0.7;'>($cnt)</span>"; ?>
                        </a>
                    <?php endforeach; ?>
                    <span style="font-size:0.82rem;color:#999;margin-left:4px;"><?= $total_rows ?> record<?= $total_rows != 1 ? 's' : '' ?></span>

                    <!-- Compact Month/Year filter -->
                    <form method="GET" action="dashboard.php" class="res-date-filter">
                        <?php if ($filter_status !== 'All'): ?>
                            <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                        <?php endif; ?>
                        <span class="rd-label">
                            <i class="fas fa-calendar-alt"></i> Date:
                        </span>
                        <select name="res_month" aria-label="Month">
                            <option value="All" <?= $allowed_res_month === null ? 'selected' : '' ?>>Any Month</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $allowed_res_month === $m ? 'selected' : '' ?>>
                                    <?= $month_names_full[$m - 1] ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <select name="res_year" aria-label="Year">
                            <option value="All" <?= $allowed_res_year === null ? 'selected' : '' ?>>Any Year</option>
                            <?php foreach ($res_year_list as $y): ?>
                                <option value="<?= $y ?>" <?= $allowed_res_year === $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit"><i class="fas fa-search"></i> Apply</button>
                        <?php if ($has_date_filter): ?>
                            <a href="dashboard.php<?= $persist_status_qs ? '?' . ltrim($persist_status_qs, '&') : '' ?>"
                               class="rd-reset" title="Clear date filter">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr><th>ID</th><th>Guest</th><th>Branch</th><th>Check-in</th><th>Status</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($data as $row): ?>
                            <tr>
                                <td><span style="font-weight:bold; color:var(--primary);">#<?= $row['reservation_id'] ?></span></td>
                                <td><?= htmlspecialchars($row['full_name']) ?></td>
                                <td><?= htmlspecialchars($row['branch_name']) ?></td>
                                <td><?= date('M d, Y', strtotime($row['reservation_date'])) ?></td>
                                <td>
                                    <?php $sc = match($row['status']) {
                                        'Confirmed'=>'confirmed','Pending'=>'pending',
                                        'Completed'=>'completed','Cancelled'=>'cancelled',default=>'cancelled'}; ?>
                                    <span class="status <?= $sc ?>"><?= $row['status'] ?></span>
                                </td>
                                <td>
                                    <?php if($row['status'] === 'Pending'): ?>
                                        <div class="action-buttons">
                                            <a href="?approve=<?= $row['reservation_id'] ?>" class="btn-action"
                                               onclick="return confirm('Approve reservation #<?= $row['reservation_id'] ?>?')">
                                                <i class="fas fa-check"></i> Approve
                                            </a>
                                            <a href="?reject=<?= $row['reservation_id'] ?>" class="btn-action btn-reject"
                                               onclick="return confirm('Reject reservation #<?= $row['reservation_id'] ?>?')">
                                                <i class="fas fa-times"></i> Reject
                                            </a>
                                        </div>
                                    <?php elseif($row['status'] === 'Confirmed'): ?>
                                        <span style="color:#2ecc71;font-size:0.9rem;"><i class="fas fa-check-circle"></i> Confirmed</span>
                                    <?php elseif($row['status'] === 'Completed'): ?>
                                        <span style="color:#3498db;font-size:0.9rem;"><i class="fas fa-flag-checkered"></i> Completed</span>
                                    <?php else: ?>
                                        <span style="color:#e74c3c;font-size:0.9rem;"><i class="fas fa-times-circle"></i> Cancelled</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if(empty($data)): ?>
                        <p style="text-align:center; padding: 2rem; color: #888;">No reservations found.</p>
                    <?php endif; ?>
                </div>

                <?php if ($total_pages > 1):
                    $sp_parts = [];
                    if ($filter_status !== 'All')        $sp_parts[] = 'status='    . urlencode($filter_status);
                    if ($allowed_res_month !== null)     $sp_parts[] = 'res_month=' . $allowed_res_month;
                    if ($allowed_res_year  !== null)     $sp_parts[] = 'res_year='  . $allowed_res_year;
                    $sp = $sp_parts ? '&' . implode('&', $sp_parts) : '';
                ?>
                <div class="pagination">
                    <a href="dashboard.php?page=<?= $current_page-1 . $sp ?>" class="page-btn <?= $current_page<=1?'disabled':'' ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <?php
                    $win=2; $s2=max(1,$current_page-$win); $e2=min($total_pages,$current_page+$win);
                    if($s2>1): ?><a href="dashboard.php?page=1<?= $sp ?>" class="page-btn">1</a><?php
                        if($s2>2): ?><span style="padding:8px 4px;color:#aaa;">…</span><?php endif;
                    endif;
                    for($p=$s2;$p<=$e2;$p++): ?>
                        <a href="dashboard.php?page=<?= $p.$sp ?>" class="page-btn <?= $p===$current_page?'active':'' ?>"><?= $p ?></a>
                    <?php endfor;
                    if($e2<$total_pages):
                        if($e2<$total_pages-1): ?><span style="padding:8px 4px;color:#aaa;">…</span><?php endif; ?>
                        <a href="dashboard.php?page=<?= $total_pages.$sp ?>" class="page-btn"><?= $total_pages ?></a>
                    <?php endif; ?>
                    <a href="dashboard.php?page=<?= $current_page+1 . $sp ?>" class="page-btn <?= $current_page>=$total_pages?'disabled':'' ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                    <span class="page-info">Page <?= $current_page ?> of <?= $total_pages ?></span>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

</body>
</html>