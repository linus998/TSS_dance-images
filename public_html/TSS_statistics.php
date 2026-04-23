<?php

require_once 'AdminAccess.php';
require_once __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;

$dotenvPath = realpath(__DIR__ . '/../Static');
if ($dotenvPath === false)
    die("ERROR: Static folder not found.");

$dotenv = Dotenv::createImmutable($dotenvPath);
$dotenv->load();

$dsn = "sqlite:" . $_ENV['TSS_DB_PATH'];


try {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

function fetchSingle($pdo, $query)
{
    return $pdo->query($query)->fetchColumn();
}
function fetchGrouped($pdo, $query)
{
    return $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
}

$totalOrders = fetchSingle($pdo, "SELECT COUNT(*) FROM t_orders");
$totalAdults = fetchSingle($pdo, "SELECT COALESCE(SUM(order_Adults),0) FROM t_orders");
$totalKids = fetchSingle($pdo, "SELECT COALESCE(SUM(order_Kids),0) FROM t_orders");
$totalRevenue = fetchSingle($pdo, "SELECT COALESCE(SUM(order_totalCosts),0) FROM t_orders");
$completedOrders = fetchSingle($pdo, "SELECT COUNT(*) FROM t_orders WHERE order_Completed = 1");
$uncompletedOrders = fetchSingle($pdo, "SELECT COUNT(*) FROM t_orders WHERE order_Completed = 0");
$totalSeats = fetchSingle($pdo, "SELECT COUNT(*) FROM t_seating");
$takenSeats = fetchSingle($pdo, "SELECT COUNT(*) FROM t_seating WHERE Seating_IsTaken = 1");
$freeSeats = $totalSeats - $takenSeats;
$orders = $pdo->query("SELECT * FROM t_orders ORDER BY order_ID ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Statistics</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap');

        :root {
            --primary: #00c6ff;
            --primary-dark: #0072ff;
            --bg-gradient: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            --glass-bg: rgba(255, 255, 255, 0.1);
            --text-light: #eee;
            --text-muted: #bbb;
            --border-glass: rgba(255, 255, 255, 0.2);
            --red: #e53935;
            --red-dark: #b71c1c;
            --green: #4caf50;
            --green-dark: #2e7d32;
            --blue: #2196f3;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background: var(--bg-gradient);
            color: var(--text-light);
            padding: 24px;
        }

        h1 {
            text-align: center;
            margin-bottom: 24px;
            font-size: 2rem;
            color: var(--primary);
            margin-top: 50px
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: blur(15px);
            padding: 20px;
            border-radius: 16px;
            border: 1.5px solid var(--border-glass);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
            text-align: center;
            transition: transform 0.2s ease;
        }

        .stat-card:hover {
            transform: scale(1.02);
            border-color: var(--primary);
        }

        .stat-title {
            font-size: 1.1rem;
            margin-bottom: 10px;
            color: var(--text-muted);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-light);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--glass-bg);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
        }

        th,
        td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-glass);
            color: var(--text-light);
        }

        th {
            background-color: rgba(255, 255, 255, 0.05);
            font-weight: 600;
            color: var(--primary);
        }

        tr:hover {
            background-color: rgba(0, 198, 255, 0.1);
        }

        .status-complete {
            color: var(--green);
            font-weight: 600;
        }

        .status-pending {
            color: var(--red);
            font-weight: 600;
        }

        #topbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 24px;
            background: rgba(255 255 255 / 0.1);
            backdrop-filter: blur(15px);
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.6);
            border-radius: 0 0 20px 20px;
            color: var(--text-light, #fff);
            font-weight: 600;
            user-select: none;
            z-index: 1000;
        }

        #topbar>div {
            display: flex;
            gap: 12px;
        }

        #topbar button {
            padding: 8px 16px;
            font-weight: 600;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.4);
            color: white;
            user-select: none;
        }

        #topbar button.page-btn {
            background-color: #2196f3;
            /* blue */
        }

        #topbar button.page-btn:hover {
            background-color: #0d47a1;
            box-shadow: 0 0 10px #0d47a1;
        }

        #topbar button.logout-btn {
            background-color: #333;
            /* dark grey */
        }

        #topbar button.logout-btn:hover {
            background-color: #555;
            box-shadow: 0 0 8px #555;
        }

        /* Chart container */
        .charts-container {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            justify-content: center;
            margin-bottom: 40px;
        }

        .chart-card {
            background: var(--glass-bg);
            backdrop-filter: blur(15px);
            padding: 20px;
            border-radius: 16px;
            border: 1.5px solid var(--border-glass);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
            width: 320px;
            max-width: 100%;
            text-align: center;
        }

        .chart-title {
            font-size: 1.2rem;
            margin-bottom: 16px;
            color: var(--primary);
            font-weight: 600;
        }
    </style>
</head>

<body>
    <div id="topbar">
        <div>
            <button onclick="window.location.href='TSS_process.php'" class="page-btn">Process</button>
            <button onclick="window.location.href='TSS_overview.php'" class="page-btn">Overview</button>
            <button onclick="window.location.href='TSS_statistics.php'" class="page-btn">Statistics</button>
            <button onclick="window.location.href='TSS_settings.php'" class="page-btn">Settings</button>
        </div>
        <div>
            <button onclick="window.location.href='AdminAccesPannel.php'" class="page-btn">Admin Panel</button>
            <form action="logout.php" method="post" style="display:inline;">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </div>
    <h1>📊 Booking Statistics</h1>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-title">Total Orders</div>
            <div class="stat-value"><?= $totalOrders ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Adults Booked</div>
            <div class="stat-value"><?= $totalAdults ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Kids Booked</div>
            <div class="stat-value"><?= $totalKids ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Revenue (€)</div>
            <div class="stat-value"><?= number_format($totalRevenue, 2) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Completed Orders</div>
            <div class="stat-value"><?= $completedOrders ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Pending Orders</div>
            <div class="stat-value"><?= $uncompletedOrders ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Seats Taken</div>
            <div class="stat-value"><?= $takenSeats ?> / <?= $totalSeats ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Free Seats</div>
            <div class="stat-value"><?= $freeSeats ?></div>
        </div>
    </div>

    <div class="charts-container">
        <div class="chart-card">
            <div class="chart-title">Adults vs Kids</div>
            <canvas id="adultsKidsChart" width="300" height="300"></canvas>
        </div>

        <div class="chart-card">
            <div class="chart-title">Completed vs Pending Orders</div>
            <canvas id="ordersStatusChart" width="300" height="300"></canvas>
        </div>

        <div class="chart-card">
            <div class="chart-title">Seats Taken vs Free</div>
            <canvas id="seatsChart" width="300" height="300"></canvas>
        </div>
    </div>

    <h2 style="margin-bottom:16px; color: var(--primary);">🗂️ Order Details</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Show</th>
                <th>Adults</th>
                <th>Kids</th>
                <th>Wheelchair</th>
                <th>Pref Seats</th>
                <th>Total (€)</th>
                <th>Sent</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td><?= htmlspecialchars($order['order_ID']) ?></td>
                    <td><?= htmlspecialchars($order['order_Name']) ?></td>
                    <td><?= htmlspecialchars($order['order_Show']) ?></td>
                    <td><?= htmlspecialchars($order['order_Adults']) ?></td>
                    <td><?= htmlspecialchars($order['order_Kids']) ?></td>
                    <td><?= $order['order_Wheelchair'] ? '🦽' : '—' ?></td>
                    <td><?= htmlspecialchars($order['order_PrefSeats']) ?></td>
                    <td><?= number_format($order['order_totalCosts'], 2) ?></td>
                    <td><?= $order['order_Sent'] ? '✅' : '—' ?></td>
                    <td class="<?= $order['order_Completed'] ? 'status-complete' : 'status-pending' ?>">
                        <?= $order['order_Completed'] ? 'Complete' : 'Pending' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Adults vs Kids Pie Chart
        const ctxAdultsKids = document.getElementById('adultsKidsChart').getContext('2d');
        const adultsKidsChart = new Chart(ctxAdultsKids, {
            type: 'doughnut',
            data: {
                labels: ['Adults', 'Kids'],
                datasets: [{
                    data: [<?= $totalAdults ?>, <?= $totalKids ?>],
                    backgroundColor: [
                        'rgba(33, 150, 243, 0.8)',  // blue
                        'rgba(255, 193, 7, 0.8)'    // amber
                    ],
                    borderColor: 'rgba(255, 255, 255, 0.3)',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                cutout: '65%',
                plugins: {
                    legend: {
                        labels: {
                            color: '#00c6ff',
                            font: { weight: '600', size: 14 }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,0.7)',
                        bodyFont: { weight: '600' }
                    }
                }
            }
        });

        // Completed vs Pending Doughnut Chart
        const ctxOrdersStatus = document.getElementById('ordersStatusChart').getContext('2d');
        const ordersStatusChart = new Chart(ctxOrdersStatus, {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'Pending'],
                datasets: [{
                    data: [<?= $completedOrders ?>, <?= $uncompletedOrders ?>],
                    backgroundColor: [
                        'rgba(76, 175, 80, 0.8)',   // green
                        'rgba(229, 57, 53, 0.8)'    // red
                    ],
                    borderColor: 'rgba(255, 255, 255, 0.3)',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                cutout: '65%',
                plugins: {
                    legend: {
                        labels: {
                            color: '#00c6ff',
                            font: { weight: '600', size: 14 }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,0.7)',
                        bodyFont: { weight: '600' }
                    }
                }
            }
        });

        // Seats Taken vs Free Doughnut Chart
        const ctxSeats = document.getElementById('seatsChart').getContext('2d');
        const seatsChart = new Chart(ctxSeats, {
            type: 'doughnut',
            data: {
                labels: ['Taken', 'Free'],
                datasets: [{
                    data: [<?= $takenSeats ?>, <?= $freeSeats ?>],
                    backgroundColor: [
                        'rgba(233, 30, 99, 0.8)',  // pinkish red
                        'rgba(76, 175, 80, 0.8)'   // green
                    ],
                    borderColor: 'rgba(255, 255, 255, 0.3)',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                cutout: '65%',
                plugins: {
                    legend: {
                        labels: {
                            color: '#00c6ff',
                            font: { weight: '600', size: 14 }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(5, 86, 97, 0.7)',
                        bodyFont: { weight: '600' }
                    }
                }
            }
        });
    </script>
    <h2 style="margin-top: 40px; margin-bottom: 30px; color: var(--primary);">📊 Detailed Statistics</h2>
    <table>
        <tr>
            <th>Statistiek</th>
            <th>Waarde</th>
        </tr>
        <tr>
            <td>Aantal bestellingen</td>
            <td><?= $totalOrders ?></td>
        </tr>
        <tr>
            <td>Voltooide bestellingen</td>
            <td><?= $completedOrders ?></td>
        </tr>
        <tr>
            <td>Openstaande bestellingen</td>
            <td><?= $uncompletedOrders ?></td>
        </tr>
        <tr>
            <td>Totaal volwassenen</td>
            <td><?= $totalAdults ?></td>
        </tr>
        <tr>
            <td>Totaal kinderen</td>
            <td><?= $totalKids ?></td>
        </tr>
        <tr>
            <td>Rolstoelgebruikers</td>
            <td><?= fetchSingle($pdo, "SELECT COUNT(*) FROM t_orders WHERE order_Wheelchair = 1") ?></td>
        </tr>
        <tr>
            <td>Totaal inkomsten (€)</td>
            <td>€ <?= number_format($totalRevenue, 2) ?></td>
        </tr>
        <tr>
            <td>Gemiddelde prijs per bestelling</td>
            <td>€ <?= number_format(fetchSingle($pdo, "SELECT AVG(order_totalCosts) FROM t_orders"), 2) ?></td>
        </tr>
        <tr>
            <td>Gemiddelde prijs per persoon</td>
            <td>€
                <?= number_format(fetchSingle($pdo, "SELECT SUM(order_totalCosts) * 1.0 / SUM(order_Adults + CAST(order_Kids AS INTEGER)) FROM t_orders"), 2) ?>
            </td>
        </tr>
    </table>
    <h2 style="margin-top: 40px; margin-bottom: 30px; color: var(--primary);">🧍 Statistieken per Show</h2>
    <table>
        <tr>
            <th>Show</th>
            <th>Bestellingen</th>
            <th>Voltooid</th>
            <th>Inkomen (€)</th>
            <th>Volwassenen</th>
            <th>Kinderen</th>
            <th>Rolstoel</th>
        </tr>
        <?php
        $shows = fetchGrouped($pdo, "SELECT DISTINCT order_Show FROM t_orders");
        foreach ($shows as $s) {
            $show = $s['order_Show'];
            echo "<tr>";
            echo "<td>" . htmlspecialchars($show) . "</td>";
            echo "<td>" . fetchSingle($pdo, "SELECT COUNT(*) FROM t_orders WHERE order_Show = " . $pdo->quote($show)) . "</td>";
            echo "<td>" . fetchSingle($pdo, "SELECT COUNT(*) FROM t_orders WHERE order_Show = " . $pdo->quote($show) . " AND order_Completed = 1") . "</td>";
            echo "<td>€ " . number_format(fetchSingle($pdo, "SELECT SUM(order_totalCosts) FROM t_orders WHERE order_Show = " . $pdo->quote($show)), 2) . "</td>";
            echo "<td>" . fetchSingle($pdo, "SELECT SUM(order_Adults) FROM t_orders WHERE order_Show = " . $pdo->quote($show)) . "</td>";
            echo "<td>" . fetchSingle($pdo, "SELECT SUM(order_Kids) FROM t_orders WHERE order_Show = " . $pdo->quote($show)) . "</td>";
            echo "<td>" . fetchSingle($pdo, "SELECT COUNT(*) FROM t_orders WHERE order_Show = " . $pdo->quote($show) . " AND order_Wheelchair = 1") . "</td>";
            echo "</tr>";
        }
        ?>
    </table>

    <h2 style="margin-top: 40px; margin-bottom: 30px; color: var(--primary);">🪑 Stoel Bezetting</h2>
    <table>
        <tr>
            <th>Statistiek</th>
            <th>Waarde</th>
        </tr>
        <tr>
            <td>Totaal stoelen</td>
            <td><?= $totalSeats ?></td>
        </tr>
        <tr>
            <td>Ingenomen stoelen</td>
            <td><?= $takenSeats ?></td>
        </tr>
        <tr>
            <td>Vrije stoelen</td>
            <td><?= $freeSeats ?></td>
        </tr>
        <tr>
            <td>Gem. stoelen per bestelling</td>
            <td><?= number_format(fetchSingle($pdo, "SELECT AVG(order_AssignedSeats) FROM t_orders WHERE order_AssignedSeats > 0"), 2) ?>
            </td>
        </tr>
        <tr>
            <td>Bezettingsgraad (%)</td>
            <td><?= number_format(fetchSingle($pdo, "SELECT 100.0 * SUM(Seating_IsTaken) / COUNT(*) FROM t_seating"), 1) ?>%
            </td>
        </tr>
    </table>

    <h2 style="margin-top: 40px; margin-bottom: 30px; color: var(--primary);">📧 Email Statistieken</h2>
    <table>
        <tr>
            <td>Emails verzonden</td>
            <td><?= fetchSingle($pdo, "SELECT COUNT(*) FROM t_orders WHERE order_Sent = 1") ?></td>
        </tr>
        <tr>
            <td>Emails nog niet verzonden</td>
            <td><?= fetchSingle($pdo, "SELECT COUNT(*) FROM t_orders WHERE order_Sent = 0") ?></td>
        </tr>
        <tr>
            <td>Voltooid maar niet verzonden</td>
            <td><?= fetchSingle($pdo, "SELECT COUNT(*) FROM t_orders WHERE order_Completed = 1 AND order_Sent = 0") ?>
            </td>
        </tr>
        <tr>
            <td>Bestellingen met opmerkingen</td>
            <td><?= fetchSingle($pdo, "SELECT COUNT(*) FROM t_orders WHERE order_Message IS NOT NULL AND order_Message != ''") ?>
            </td>
        </tr>
    </table>

    <h2 style="margin-top: 40px; margin-bottom: 30px; color: var(--primary);">🔢 Ratio Statistieken</h2>
    <table>
        <tr>
            <td>Volwassenen/kinderen ratio</td>
            <td><?= number_format(fetchSingle($pdo, "SELECT SUM(order_Adults)*1.0 / SUM(order_Kids) FROM t_orders"), 2) ?>
            </td>
        </tr>
        <tr>
            <td>Voltooiingsgraad</td>
            <td><?= number_format(fetchSingle($pdo, "SELECT 100.0 * SUM(order_Completed) / COUNT(*) FROM t_orders"), 2) ?>%
            </td>
        </tr>
        <tr>
            <td>Rolstoelgebruikers ratio</td>
            <td><?= number_format(fetchSingle($pdo, "SELECT 100.0 * COUNT(*) FILTER (WHERE order_Wheelchair = 1) / COUNT(*) FROM t_orders"), 2) ?>%
            </td>
        </tr>
        <tr>
            <td>Voorkeursstoelen gebruikt</td>
            <td><?= number_format(fetchSingle($pdo, "SELECT 100.0 * COUNT(*) FILTER (WHERE order_PrefSeats IS NOT NULL AND order_PrefSeats != '') / COUNT(*) FROM t_orders"), 2) ?>%
            </td>
        </tr>
    </table>
</body>

</html>