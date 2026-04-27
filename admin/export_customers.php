<?php
/**
 * export_customers.php
 *
 * Streams the registered customers list as a downloadable CSV file.
 * Excel-compatible (UTF-8 BOM, CRLF line endings).
 *
 * Access:
 *   - Admin only. Uses the same session check as dashboard.php.
 *
 * Supported filters (passed via GET):
 *   - q : free-text search across full_name, email, contact_number
 */

include '../db.php';

// ---- Auth check -----------------------------------------------------------
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    http_response_code(403);
    die("Access Denied");
}

// ---- Build query (same shape as the customers view) -----------------------
$search = trim($_GET['q'] ?? '');
$where_sql = '';
$params = [];
if ($search !== '') {
    $where_sql = "WHERE c.full_name LIKE :q OR c.email LIKE :q OR c.contact_number LIKE :q";
    $params[':q'] = '%' . $search . '%';
}

// LEFT JOIN to users so we can include account status (pure read-only join,
// keeps the export complete even for customers with no user account).
$sql = "
    SELECT
        c.customer_id,
        c.full_name,
        c.email,
        c.contact_number,
        c.address,
        c.created_at,
        COALESCE((
            SELECT COUNT(*) FROM reservations r WHERE r.customer_id = c.customer_id
        ), 0) AS total_reservations,
        COALESCE((
            SELECT MAX(r.reservation_date) FROM reservations r WHERE r.customer_id = c.customer_id
        ), NULL) AS last_reservation_date,
        u.is_active
    FROM customers c
    LEFT JOIN users u ON u.customer_id = c.customer_id
    $where_sql
    ORDER BY c.customer_id ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

// ---- Send headers ---------------------------------------------------------
$timestamp = date('Y-m-d_His');
$filename  = "checkmates_customers_{$timestamp}.csv";

// Clean any prior output (e.g. session warnings) before sending the file.
if (ob_get_level()) { ob_end_clean(); }

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// UTF-8 BOM so Excel correctly recognises non-ASCII characters (₱, é, ñ…)
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// ---- Header row -----------------------------------------------------------
fputcsv($output, [
    'Customer ID',
    'Full Name',
    'Email',
    'Contact Number',
    'Address',
    'Total Reservations',
    'Last Reservation',
    'Account Status',
    'Date Registered',
]);

// ---- Data rows ------------------------------------------------------------
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status = is_null($row['is_active'])
              ? 'No Account'
              : ((int)$row['is_active'] === 1 ? 'Active' : 'Inactive');

    $last_res = $row['last_reservation_date']
                ? date('Y-m-d', strtotime($row['last_reservation_date']))
                : '—';

    $registered = $row['created_at']
                  ? date('Y-m-d H:i', strtotime($row['created_at']))
                  : '—';

    fputcsv($output, [
        $row['customer_id'],
        $row['full_name'],
        $row['email'],
        $row['contact_number'] ?? '',
        $row['address'] ?? '',
        (int)$row['total_reservations'],
        $last_res,
        $status,
        $registered,
    ]);
}

fclose($output);
exit;