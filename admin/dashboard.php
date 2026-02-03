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

$view = $_GET['view'] ?? 'dashboard';

if ($view === 'customers') {
    $data = $pdo->query("SELECT * FROM customers ORDER BY customer_id DESC")->fetchAll();
    $pageTitle = "Registered Customers";
} else {
    $data = $pdo->query("SELECT r.*, c.full_name, b.branch_name 
                         FROM reservations r 
                         JOIN customers c ON r.customer_id = c.customer_id 
                         JOIN branches b ON r.branch_id = b.branch_id 
                         ORDER BY r.reservation_id DESC")->fetchAll();
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
            width: 280px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            min-height: 100vh;
            border-right: 1px solid rgba(255, 255, 255, 0.3);
            display: flex;
            flex-direction: column;
            padding: 2rem;
            box-shadow: 4px 0 15px rgba(0,0,0,0.05);
        }

        .brand {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-links {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
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
            margin-top: auto;
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
        }

        h1 {
            color: var(--primary-dark);
            margin-bottom: 1.5rem;
            font-size: 2rem;
        }

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
            border-radius: 8px 8px 0 0;
        }
        
        th:first-child { border-top-left-radius: 8px; }
        th:last-child { border-top-right-radius: 8px; border-radius: 0 8px 0 0; }
        th:only-child { border-radius: 8px 8px 0 0; }

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
        .status.confirmed { background: #d4edda; color: #155724; }
        .status.pending { background: #fff3cd; color: #856404; }
        .status.cancelled { background: #f8d7da; color: #721c24; }

        .btn-action {
            text-decoration: none;
            padding: 6px 15px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: 0.2s;
            background: var(--primary);
            color: white;
        }
        .btn-action:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
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
        <div class="glass-panel">
            <h1><?= $pageTitle ?></h1>
            
            <div style="overflow-x: auto;">
                <table>
                    <?php if ($view === 'customers'): ?>
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

                    <?php else: ?>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Guest</th>
                                <th>Branch</th>
                                <th>Check-in</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($data as $row): ?>
                            <tr>
                                <td><span style="font-weight:bold; color:var(--primary);">#<?= $row['reservation_id'] ?></span></td>
                                <td><?= htmlspecialchars($row['full_name']) ?></td>
                                <td><?= htmlspecialchars($row['branch_name']) ?></td>
                                <td><?= date('M d, Y', strtotime($row['reservation_date'])) ?></td>
                                <td>
                                    <?php 
                                        $statusClass = match($row['status']) {
                                            'Confirmed' => 'confirmed',
                                            'Pending' => 'pending',
                                            default => 'cancelled'
                                        };
                                    ?>
                                    <span class="status <?= $statusClass ?>">
                                        <?= $row['status'] ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if($row['status'] == 'Pending'): ?>
                                        <a href="?approve=<?= $row['reservation_id'] ?>" class="btn-action">
                                            <i class="fas fa-check"></i> Approve
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#aaa; font-size:0.9rem;"><i class="fas fa-check-circle"></i> Done</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php endif; ?>
                </table>
                
                <?php if(empty($data)): ?>
                    <p style="text-align:center; padding: 2rem; color: #888;">No records found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>