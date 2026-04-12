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

// Overview stats
// Only count Pending reservations that have been paid
$stats['pending_reservations'] = $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'Pending' AND payment_status = 'Paid'")->fetchColumn();
$stats['todays_reservations'] = $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'Confirmed' AND reservation_date = CURDATE()")->fetchColumn();
$stats['this_month_bookings'] = $pdo->query("SELECT COUNT(*) FROM reservations WHERE MONTH(reservation_date) = MONTH(CURDATE()) AND YEAR(reservation_date) = YEAR(CURDATE()) AND status IN ('Confirmed', 'Completed')")->fetchColumn();
$stats['this_month_revenue'] = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM reservations WHERE MONTH(reservation_date) = MONTH(CURDATE()) AND YEAR(reservation_date) = YEAR(CURDATE()) AND status IN ('Confirmed', 'Completed')")->fetchColumn();
$stats['total_customers'] = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$stats['avg_rating'] = $pdo->query("SELECT COALESCE(AVG(rating), 0) FROM feedback WHERE MONTH(feedback_date) = MONTH(CURDATE())")->fetchColumn();
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
    GROUP BY reservation_type
";
$reservation_types = $pdo->query($type_query)->fetchAll(PDO::FETCH_ASSOC);

// Get data for current view
if ($view === 'customers') {
    $data = $pdo->query("SELECT * FROM customers ORDER BY customer_id DESC")->fetchAll();
    $pageTitle = "Registered Customers";
} elseif ($view === 'analytics') {
    $pageTitle = "Booking Analytics";
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
                <h1><?= $pageTitle ?></h1>
                <p style="color: #7f8c8d; margin-bottom: 2rem;">Booking and Performance Insights</p>
                
                <!-- Statistics Cards -->
                <div class="stats-grid">
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
                        <h3>This Month</h3>
                        <div class="stat-value"><?= $stats['this_month_bookings'] ?></div>
                        <div class="stat-label">Total bookings</div>
                    </div>

                    <div class="stat-card">
                        <h3>Revenue (This Month)</h3>
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
                        <div class="stat-label">This month</div>
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
                <h2 style="margin-bottom: 1rem;">Branch Performance</h2>
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