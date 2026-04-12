<?php
include '../db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    die("Access Denied");
}

if (isset($_GET['approve'])) {
    $pdo->prepare("UPDATE reservations SET status='Confirmed' WHERE reservation_id=?")->execute([$_GET['approve']]);
    header("Location: dashboard.php");
    exit;
}

if (isset($_GET['reject'])) {
    $pdo->prepare("UPDATE reservations SET status='Cancelled' WHERE reservation_id=?")->execute([$_GET['reject']]);
    header("Location: dashboard.php");
    exit;
}

$view = $_GET['view'] ?? 'dashboard';
// Auto-complete confirmed reservations whose date has passed
$pdo->query("
    UPDATE reservations 
    SET status = 'Completed' 
    WHERE status = 'Confirmed' 
    AND reservation_date < CURDATE()
");
// Fetch analytics data
$stats = [];

// ── Analytics date filter ─────────────────────────────────────────────────
$current_year  = (int)date('Y');
$current_month = (int)date('m');
$filter_month  = (isset($_GET['month']) && (int)$_GET['month'] >= 1 && (int)$_GET['month'] <= 12)
                 ? (int)$_GET['month'] : $current_month;
$filter_year   = (isset($_GET['year'])  && (int)$_GET['year']  >= 2020 && (int)$_GET['year']  <= $current_year + 1)
                 ? (int)$_GET['year']  : $current_year;
$is_default_period = ($filter_month === $current_month && $filter_year === $current_year);

// Overview stats
// Only count Pending reservations that have been paid
$stats['pending_reservations'] = $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'Pending' AND payment_status = 'Paid'")->fetchColumn();
$stats['todays_reservations'] = $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'Confirmed' AND reservation_date = CURDATE()")->fetchColumn();
$stats['this_month_bookings'] = $pdo->query("SELECT COUNT(*) FROM reservations WHERE MONTH(reservation_date) = $filter_month AND YEAR(reservation_date) = $filter_year AND status IN ('Confirmed', 'Completed')")->fetchColumn();
$stats['this_month_revenue'] = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM reservations WHERE MONTH(reservation_date) = $filter_month AND YEAR(reservation_date) = $filter_year AND status IN ('Confirmed', 'Completed')")->fetchColumn();
$stats['total_customers'] = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$stats['avg_rating'] = $pdo->query("SELECT COALESCE(AVG(rating), 0) FROM feedback WHERE MONTH(feedback_date) = $filter_month AND YEAR(feedback_date) = $filter_year")->fetchColumn();
// Monthly booking trends (last 12 months)
$monthly_query = "
    SELECT 
        DATE_FORMAT(reservation_date, '%Y-%m') as month,
        DATE_FORMAT(reservation_date, '%b %Y') as month_label,
        SUM(CASE WHEN status IN ('Pending', 'Confirmed', 'Completed') THEN 1 ELSE 0 END) as total_bookings,
        SUM(CASE WHEN status IN ('Confirmed', 'Completed') THEN 1 ELSE 0 END) as successful_bookings,
        SUM(CASE WHEN status IN ('Confirmed', 'Completed') THEN total_amount ELSE 0 END) as revenue,
        SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled_bookings
    FROM reservations
    WHERE reservation_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(reservation_date, '%Y-%m'), DATE_FORMAT(reservation_date, '%b %Y')
    ORDER BY month ASC
";
$monthly_data = $pdo->query($monthly_query)->fetchAll(PDO::FETCH_ASSOC);

// Branch performance
$branch_query = "
    SELECT 
        b.branch_id,
        b.branch_name,
        SUM(CASE WHEN r.status IN ('Pending', 'Confirmed', 'Completed') THEN 1 ELSE 0 END) as total_reservations,
        SUM(CASE WHEN r.status = 'Confirmed' THEN 1 ELSE 0 END) as confirmed_count,
        SUM(CASE WHEN r.status = 'Completed' THEN 1 ELSE 0 END) as completed_count,
        COALESCE(SUM(CASE WHEN r.status IN ('Confirmed', 'Completed') THEN r.total_amount ELSE 0 END), 0) as total_revenue,
        COALESCE(
            SUM(CASE WHEN r.status IN ('Confirmed', 'Completed') THEN r.total_amount ELSE 0 END) /
            NULLIF(SUM(CASE WHEN r.status IN ('Confirmed', 'Completed') THEN 1 ELSE 0 END), 0)
        , 0) as avg_revenue_per_booking,
        COALESCE((
            SELECT AVG(f.rating)
            FROM feedback f
            WHERE f.branch_id = b.branch_id
        ), 0) as avg_rating
    FROM branches b
    LEFT JOIN reservations r ON b.branch_id = r.branch_id
        AND MONTH(r.reservation_date) = $filter_month
        AND YEAR(r.reservation_date)  = $filter_year
    GROUP BY b.branch_id, b.branch_name
    ORDER BY total_revenue DESC
";
$branch_stats = $pdo->query($branch_query)->fetchAll(PDO::FETCH_ASSOC);

// Reservation type distribution
$type_query = "
    SELECT 
        reservation_type,
        COUNT(*) as count,
        SUM(total_amount) as revenue
    FROM reservations
    WHERE status IN ('Confirmed', 'Completed')
      AND MONTH(reservation_date) = $filter_month
      AND YEAR(reservation_date)  = $filter_year
    GROUP BY reservation_type
";
$reservation_types = $pdo->query($type_query)->fetchAll(PDO::FETCH_ASSOC);

// Get data for current view
if ($view === 'customers') {
    $data = $pdo->query("SELECT * FROM customers ORDER BY customer_id DESC")->fetchAll();
    $pageTitle = "Registered Customers";
} elseif ($view === 'analytics') {
    $pageTitle = "Booking Analytics";
} elseif ($view === 'feedback') {
    $pageTitle = "User Feedback";

    // Pagination for feedback
    $fb_per_page  = 10;
    $fb_page      = max(1, (int)($_GET['page'] ?? 1));
    $fb_total     = $pdo->query("SELECT COUNT(*) FROM feedback")->fetchColumn();
    $fb_pages     = max(1, ceil($fb_total / $fb_per_page));
    $fb_page      = min($fb_page, $fb_pages);
    $fb_offset    = ($fb_page - 1) * $fb_per_page;

    // Rating filter
    $allowed_ratings = ['All', '1', '2', '3', '4', '5'];
    $filter_rating   = (isset($_GET['rating']) && in_array($_GET['rating'], $allowed_ratings))
                       ? $_GET['rating'] : 'All';
    $rating_where    = ($filter_rating !== 'All') ? "WHERE f.rating = " . (int)$filter_rating : "";

    $fb_total = $pdo->query("SELECT COUNT(*) FROM feedback f $rating_where")->fetchColumn();
    $fb_pages = max(1, ceil($fb_total / $fb_per_page));
    $fb_page  = min($fb_page, $fb_pages);
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

    // Summary stats for feedback header cards
    $fb_stats['total']   = $pdo->query("SELECT COUNT(*) FROM feedback")->fetchColumn();
    $fb_stats['avg']     = $pdo->query("SELECT COALESCE(AVG(rating),0) FROM feedback")->fetchColumn();
    $fb_stats['5star']   = $pdo->query("SELECT COUNT(*) FROM feedback WHERE rating = 5")->fetchColumn();
    $fb_stats['1star']   = $pdo->query("SELECT COUNT(*) FROM feedback WHERE rating = 1")->fetchColumn();
} else {
// Filter
    $allowed_statuses = ['All', 'Pending', 'Confirmed', 'Completed', 'Cancelled'];
    $filter_status = isset($_GET['status']) && in_array($_GET['status'], $allowed_statuses)
                     ? $_GET['status'] : 'All';

    // NEW: Exclude unpaid checkout holds from the admin view globally
    $exclude_holds = "NOT (r.status = 'Pending' AND r.payment_status = 'Unpaid')";
    
    $where_clause = "WHERE " . $exclude_holds;
    if ($filter_status !== 'All') {
        $where_clause .= " AND r.status = " . $pdo->quote($filter_status);
    }

    // Pagination
    $per_page = 10;
    $current_page = max(1, (int)($_GET['page'] ?? 1));
    $total_rows   = $pdo->query("SELECT COUNT(*) FROM reservations r $where_clause")->fetchColumn();
    $total_pages  = max(1, ceil($total_rows / $per_page));
    $current_page = min($current_page, $total_pages);
    $offset       = ($current_page - 1) * $per_page;

    $data = $pdo->query("SELECT r.*, c.full_name, b.branch_name 
                         FROM reservations r 
                         JOIN customers c ON r.customer_id = c.customer_id 
                         JOIN branches b ON r.branch_id = b.branch_id 
                         $where_clause
                         ORDER BY r.reservation_id DESC
                         LIMIT $per_page OFFSET $offset")->fetchAll();
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

        .nav-links {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--text-dark);
            padding: 12px 20px;
            border-radius: 12px;
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .nav-links a:hover, .nav-links a.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 15px rgba(0, 119, 182, 0.3);
            transform: translateX(5px);
        }

        .logout-btn {
            margin-top: 8px;
            color: #ef476f !important;
            border: 1px solid #ef476f;
        }
        .logout-btn:hover {
            background: #ef476f !important;
            color: white !important;
        }

        .main-content {
            flex: 1;
            padding: 3rem;
            overflow-y: auto;
        }

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

        h1 {
            color: var(--primary-dark);
            margin-bottom: 1.5rem;
            font-size: 2rem;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }

        .stat-card h3 {
            color: #7f8c8d;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
        }

        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 0.3rem;
        }

        .stat-card .stat-label {
            color: #95a5a6;
            font-size: 0.8rem;
        }

        .stat-card.primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .stat-card.primary h3,
        .stat-card.primary .stat-value,
        .stat-card.primary .stat-label {
            color: white;
        }

        .stat-card.success {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }

        .stat-card.success h3,
        .stat-card.success .stat-value,
        .stat-card.success .stat-label {
            color: white;
        }

        .stat-card.info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }

        .stat-card.info h3,
        .stat-card.info .stat-value,
        .stat-card.info .stat-label {
            color: white;
        }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .chart-card h2 {
            color: #2c3e50;
            margin-bottom: 1rem;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 1rem;
        }

        th {
            background: var(--primary);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        th:first-child { border-top-left-radius: 8px; }
        th:last-child { border-top-right-radius: 8px; }

        td {
            padding: 15px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            color: #444;
            vertical-align: middle;
        }

        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(0, 119, 182, 0.05); }

        .status {
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status.confirmed  { background: #d4edda; color: #155724; }
        .status.pending    { background: #fff3cd; color: #856404; }
        .status.cancelled  { background: #f8d7da; color: #721c24; }
        .status.completed  { background: #cce5ff; color: #004085; }

        .btn-action {
            text-decoration: none;
            padding: 6px 15px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: 0.2s;
            background: var(--primary);
            color: white;
            display: inline-block;
            white-space: nowrap;
        }
        .btn-action:hover {
            filter: brightness(1.15);
            transform: scale(1.05);
        }
        .btn-action.btn-reject {
            background: #ef476f;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: nowrap;
        }

        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        /* Filter Bar */
.filter-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 1.5rem; flex-wrap: wrap; }
.filter-bar > span { font-weight: 600; color: #555; font-size: 0.9rem; }
.filter-btn {
    text-decoration: none; padding: 7px 16px; border-radius: 50px;
    font-size: 0.83rem; font-weight: 600; border: 2px solid #ddd;
    color: #555; background: white; transition: 0.2s;
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

/* ── Feedback Section ─────────────────────────────────────── */
.feedback-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 1.2rem;
    margin-bottom: 2rem;
}
.fb-stat {
    background: white;
    border-radius: 14px;
    padding: 1.2rem 1.5rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.07);
    display: flex;
    align-items: center;
    gap: 14px;
    transition: transform 0.2s, box-shadow 0.2s;
}
.fb-stat:hover { transform: translateY(-4px); box-shadow: 0 8px 22px rgba(0,0,0,0.11); }
.fb-stat .fb-icon {
    width: 46px; height: 46px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; flex-shrink: 0;
}
.fb-stat .fb-icon.blue  { background: rgba(102,126,234,0.15); color: #667eea; }
.fb-stat .fb-icon.gold  { background: rgba(243,156,18,0.15);  color: #f39c12; }
.fb-stat .fb-icon.green { background: rgba(46,204,113,0.15);  color: #27ae60; }
.fb-stat .fb-icon.red   { background: rgba(239,71,111,0.15);  color: #ef476f; }
.fb-stat .fb-text strong { display: block; font-size: 1.6rem; font-weight: 700; color: #2c3e50; line-height: 1; }
.fb-stat .fb-text span   { font-size: 0.78rem; color: #95a5a6; text-transform: uppercase; letter-spacing: 0.04em; }

/* Rating filter bar (re-use filter-btn but gold active) */
.filter-btn.f-rating.active { background: #f39c12; border-color: #f39c12; color: white; box-shadow: 0 3px 10px rgba(243,156,18,0.35); }

/* Feedback card grid */
.feedback-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.2rem;
    margin-top: 0.5rem;
}
.feedback-card {
    background: white;
    border-radius: 16px;
    padding: 1.4rem 1.5rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.07);
    border-left: 4px solid var(--primary);
    display: flex;
    flex-direction: column;
    gap: 10px;
    transition: transform 0.2s, box-shadow 0.2s;
    position: relative;
}
.feedback-card:hover { transform: translateY(-4px); box-shadow: 0 10px 28px rgba(0,0,0,0.11); }
.feedback-card .fc-header {
    display: flex; align-items: center; gap: 12px;
}
.fc-avatar {
    width: 38px; height: 38px; border-radius: 50%;
    background: var(--secondary); color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 0.95rem; flex-shrink: 0;
}
.fc-meta strong { display: block; font-size: 0.95rem; color: #2c3e50; }
.fc-meta span   { font-size: 0.78rem; color: #95a5a6; }
.fc-stars { color: #f39c12; font-size: 0.95rem; letter-spacing: 2px; }
.fc-stars.low { color: #e74c3c; }
.fc-stars.mid { color: #f39c12; }
.fc-stars.high { color: #27ae60; }
.fc-comment {
    font-size: 0.9rem; color: #555; line-height: 1.55;
    border-top: 1px solid rgba(0,0,0,0.06);
    padding-top: 10px; margin-top: 2px;
    font-style: italic;
}
.fc-footer {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 0.78rem; color: #aaa; margin-top: auto; padding-top: 8px;
    border-top: 1px solid rgba(0,0,0,0.05);
}
.fc-branch {
    background: rgba(0,119,182,0.1); color: var(--primary-dark);
    padding: 3px 10px; border-radius: 50px; font-size: 0.76rem; font-weight: 600;
}
.fc-badge {
    position: absolute; top: 14px; right: 14px;
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8rem; font-weight: 700; color: white;
}
.fc-badge.r5,.fc-badge.r4 { background: #27ae60; }
.fc-badge.r3               { background: #f39c12; }
.fc-badge.r2,.fc-badge.r1  { background: #e74c3c; }

@media (max-width: 768px) {
    .feedback-grid { grid-template-columns: 1fr; }
    .feedback-summary { grid-template-columns: 1fr 1fr; }
}

/* ── Analytics Date Filter ────────────────────────────────────── */
.analytics-filter-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 2rem;
    background: white;
    border: 1px solid rgba(0,119,182,0.15);
    border-radius: 50px;
    padding: 10px 20px;
    box-shadow: 0 2px 10px rgba(0,119,182,0.08);
}
.analytics-filter-bar .filter-label {
    font-size: 0.88rem;
    font-weight: 600;
    color: #555;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 7px;
}
.analytics-filter-bar .filter-label i { color: var(--primary); }
.af-select {
    appearance: none;
    -webkit-appearance: none;
    background: #f4f8fc url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24'%3E%3Cpath fill='%230077b6' d='M7 10l5 5 5-5z'/%3E%3C/svg%3E") no-repeat right 10px center;
    border: 2px solid #ddd;
    border-radius: 50px;
    padding: 7px 32px 7px 14px;
    font-size: 0.875rem;
    font-weight: 600;
    color: #2c3e50;
    cursor: pointer;
    transition: border-color 0.2s, box-shadow 0.2s;
    outline: none;
}
.af-select:hover, .af-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0,119,182,0.12);
}
.af-apply-btn {
    background: var(--primary);
    color: white;
    border: none;
    padding: 8px 22px;
    border-radius: 50px;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: filter 0.2s, transform 0.15s, box-shadow 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 3px 10px rgba(0,119,182,0.3);
}
.af-apply-btn:hover {
    filter: brightness(1.12);
    transform: translateY(-1px);
    box-shadow: 0 5px 15px rgba(0,119,182,0.4);
}
.af-reset-link {
    font-size: 0.8rem;
    color: #999;
    text-decoration: none;
    margin-left: 2px;
    padding: 7px 12px;
    border-radius: 50px;
    transition: color 0.2s, background 0.2s;
    white-space: nowrap;
}
.af-reset-link:hover { color: #ef476f; background: rgba(239,71,111,0.07); }
.af-period-badge {
    margin-left: auto;
    font-size: 0.78rem;
    color: var(--primary);
    background: rgba(0,119,182,0.1);
    padding: 5px 14px;
    border-radius: 50px;
    font-weight: 600;
    white-space: nowrap;
}
@media (max-width: 768px) {
    .analytics-filter-bar { border-radius: 16px; }
    .af-period-badge { margin-left: 0; }
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
                <a href="dashboard.php?view=customers" class="<?= $view === 'customers' ? 'active' : '' ?>">
                    <i class="fas fa-users"></i> Customers
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
        <?php if ($view === 'analytics'): ?>
            <!-- Analytics View -->
            <div class="glass-panel">
                <div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:0.5rem;">
                    <div>
                        <h1 style="margin-bottom:0.3rem;"><?= $pageTitle ?></h1>
                        <p style="color:#7f8c8d; margin:0;">Booking and Performance Insights</p>
                    </div>
                </div>

                <!-- ── Date Filter ───────────────────────────────────────── -->
                <?php
                    $month_names = ['January','February','March','April','May','June',
                                    'July','August','September','October','November','December'];
                    $year_start  = 2020;
                    $year_end    = $current_year;
                    $selected_label = $month_names[$filter_month - 1] . ' ' . $filter_year;
                ?>
                <form method="GET" action="dashboard.php" style="margin-top:1.5rem; margin-bottom:0;">
                    <input type="hidden" name="view" value="analytics">
                    <div class="analytics-filter-bar">
                        <span class="filter-label">
                            <i class="fas fa-calendar-alt"></i> Filter Period:
                        </span>

                        <!-- Month Selector -->
                        <select name="month" class="af-select" aria-label="Month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $m === $filter_month ? 'selected' : '' ?>>
                                    <?= $month_names[$m - 1] ?>
                                </option>
                            <?php endfor; ?>
                        </select>

                        <!-- Year Selector -->
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

                        <span class="af-period-badge">
                            <i class="fas fa-clock" style="margin-right:5px;"></i><?= $selected_label ?>
                        </span>
                    </div>
                </form>
                
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
                <!-- Monthly Bookings Trend -->
                <div class="chart-card">
                    <h2>Monthly Booking Trends (Last 12 Months)</h2>
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
                    <h2>Monthly Revenue Trend</h2>
                    <div class="chart-container" style="height: 350px;">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Branch Performance -->
            <div class="glass-panel">
                <h2 style="margin-bottom: 1rem;">Branch Performance <span style="font-size:0.75rem;font-weight:500;color:var(--primary);background:rgba(0,119,182,0.1);padding:4px 12px;border-radius:50px;margin-left:10px;vertical-align:middle;"><?= $selected_label ?></span></h2>
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
                            tension: 0.4,
                            fill: true,
                            borderWidth: 3
                        }, {
                            label: 'Successful Bookings',
                            data: monthlyData.map(d => d.successful_bookings),
                            borderColor: '#f5576c',
                            backgroundColor: 'rgba(245, 87, 108, 0.1)',
                            tension: 0.4,
                            fill: true,
                            borderWidth: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
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
                            borderWidth: 3,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
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
                            borderWidth: 2,
                            borderRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₱' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            </script>

        <?php elseif ($view === 'feedback'): ?>
            <!-- ══════════════════════ FEEDBACK VIEW ══════════════════════ -->
            <div class="glass-panel">
                <h1><i class="fas fa-comment-dots" style="color:var(--secondary);margin-right:10px;"></i><?= $pageTitle ?></h1>
                <p style="color:#7f8c8d;margin-bottom:1.8rem;">Customer satisfaction & reviews across all branches</p>

                <!-- Summary Cards -->
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

                <!-- Rating Filter Bar -->
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

            <!-- Feedback Cards Grid -->
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
                    $comment    = htmlspecialchars($fb['comment'] ?? '');
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

            <!-- Feedback Pagination -->
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

        <?php elseif ($view === 'customers'): ?>
            <!-- Customers View -->
            <div class="glass-panel">
                <h1><?= $pageTitle ?></h1>
                
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Contact</th>
                                <th>Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($data as $row): ?>
                            <tr>
                                <td>#<?= $row['customer_id'] ?></td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <div style="width:30px; height:30px; background:var(--secondary); border-radius:50%; display:flex; align-items:center; justify-content:center; color:white; font-size:0.8rem;">
                                            <?= substr($row['full_name'], 0, 1) ?>
                                        </div>
                                        <?= htmlspecialchars($row['full_name']) ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($row['email']) ?></td>
                                <td><?= htmlspecialchars($row['contact_number']) ?></td>
                                <td><?= htmlspecialchars($row['address'] ?? 'N/A') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if(empty($data)): ?>
                        <p style="text-align:center; padding: 2rem; color: #888;">No customers found.</p>
                    <?php endif; ?>
                </div>
            </div>

<?php else: ?>
<div class="glass-panel">
    <h1><?= $pageTitle ?></h1>

    <!-- Filter Buttons -->
    <?php
        $statuses = ['All','Pending','Confirmed','Completed','Cancelled'];
        $filter_classes = ['All'=>'','Pending'=>'f-pending','Confirmed'=>'f-confirmed','Completed'=>'f-completed','Cancelled'=>'f-cancelled'];
    ?>
    <div class="filter-bar">
        <span><i class="fas fa-filter"></i> Filter:</span>
<?php foreach ($statuses as $s):
            $is_active = ($filter_status === $s);
            $url = 'dashboard.php' . ($s !== 'All' ? '?status=' . urlencode($s) : '');
            
            // NEW: Apply the same exclusion rule to the filter counts
            $cnt = null;
            if ($s !== 'All') {
                $cnt = $pdo->query("SELECT COUNT(*) FROM reservations r WHERE r.status = " . $pdo->quote($s) . " AND NOT (r.status = 'Pending' AND r.payment_status = 'Unpaid')")->fetchColumn();
            }
        ?>
            <a href="<?= $url ?>" class="filter-btn <?= $filter_classes[$s] ?> <?= $is_active ? 'active' : '' ?>">
                <?= $s ?><?php if($cnt !== null) echo " <span style='opacity:0.7;'>($cnt)</span>"; ?>
            </a>
        <?php endforeach; ?>
        <span style="font-size:0.82rem;color:#999;margin-left:4px;"><?= $total_rows ?> record<?= $total_rows != 1 ? 's' : '' ?></span>
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

    <!-- Pagination -->
    <?php if ($total_pages > 1):
        $sp = ($filter_status !== 'All') ? '&status=' . urlencode($filter_status) : '';
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