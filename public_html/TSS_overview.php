<?php
require_once 'AdminAccess.php';

// ===== Error Reporting Setup =====
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/php_error.log');

// ===== Autoload Check =====
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
  die("autoload.php NOT found. Please upload vendor folder.<br>");
}
require_once $autoloadPath;

// ===== Environment Setup =====
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

$dotenvPath = realpath(__DIR__ . '/../Static');
if ($dotenvPath === false) {
  die("ERROR: Static folder not found.");
}

$dotenv = Dotenv::createImmutable($dotenvPath);
$dotenv->load();

// ===== Database Connection =====
try {
  $dbPath = $_ENV['TSS_DB_PATH'];
  $db = new PDO("sqlite:$dbPath");
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  die("Database error.");
}

// Fetch all seats ordered by show, then row, then col
$stmt = $db->query("SELECT * FROM t_seating ORDER BY seating_Show, seating_Row, seating_Col");
$seatsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group seats by show and row for easy grid output
$seats = [];
foreach ($seatsRaw as $seat) {
  $show = (int) $seat['seating_Show'];
  $row = (int) $seat['seating_Row'];
  $col = (int) $seat['seating_Col'];
  if (!isset($seats[$show]))
    $seats[$show] = [];
  if (!isset($seats[$show][$row]))
    $seats[$show][$row] = [];
  $seats[$show][$row][$col] = $seat;
}

// For consistent ordering, sort rows and cols numerically
foreach ($seats as $show => &$rows) {
  ksort($rows);
  foreach ($rows as &$cols) {
    ksort($cols);
  }
}
unset($rows, $cols);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Seat Grid</title>
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
      margin-top: 50px;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      overflow-x: hidden;
      padding: 24px 30px;
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
      height: 50px;
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

    #topbar button.reset-btn {
      background-color: #e53935;
      /* red */
    }

    #topbar button.reset-btn:hover {
      background-color: #b71c1c;
      box-shadow: 0 0 10px #b71c1c;
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

    #seats {
      display: flex;
      flex-direction: column;
      gap: 30px;
      margin-top: 20px;
      user-select: none;
    }

    .show-block {
      border: 1px solid var(--border-glass);
      border-radius: 12px;
      padding: 16px;
      background: var(--glass-bg);
    }

    .show-title {
      font-weight: 700;
      font-size: 1.2rem;
      margin-bottom: 12px;
      color: var(--primary);
    }

    .seat-row {
      display: flex;
      gap: 10px;
      margin-bottom: 6px;
    }

    .seat {
      width: 44px;
      height: 44px;
      background: rgba(255, 255, 255, 0.15);
      border-radius: 12px;
      text-align: center;
      line-height: 44px;
      font-weight: 700;
      cursor: pointer;
      border: 2px solid transparent;
      color: var(--text-light);
      transition:
        background-color 0.3s ease,
        border-color 0.3s ease,
        box-shadow 0.3s ease;
      box-sizing: border-box;
      user-select: none;
    }

    .seat.taken {
      background-color: var(--red);
      color: white;
      cursor: not-allowed;
      pointer-events: none;
      border-color: var(--red-dark);
      box-shadow: none;
    }

    .seat.free {
      background-color: #666666;
      color: var(--text-light);
    }

    .seat.empty {
      background: transparent;
      border: none;
      cursor: default;
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

  <div id="seats">
    <?php
    // Output each show
    for ($show = 1; $show <= 4; $show++) {
      echo "<div class='show-block'>";
      echo "<div class='show-title'>Show $show</div>";
      if (!isset($seats[$show])) {
        echo "<div>No seats found for this show.</div>";
        echo "</div>";
        continue;
      }
      foreach ($seats[$show] as $row => $cols) {
        echo "<div class='seat-row'>";
        // Find max col to fill gaps
        $maxCol = max(array_keys($cols));
        for ($col = 1; $col <= $maxCol; $col++) {
          if (isset($cols[$col])) {
            $seat = $cols[$col];
            $taken = intval($seat['Seating_IsTaken']);
            $label = htmlspecialchars($seat['seating_Label']);
            $class = $taken ? "seat taken" : "seat free";
            echo "<div class='$class' title='Seat $label (Row $row, Col $col)'>$label</div>";
          } else {
            // Empty space if no seat in this col
            echo "<div class='seat empty'></div>";
          }
        }
        echo "</div>";
      }
      echo "</div>";
    }
    ?>
  </div>
</body>

</html>