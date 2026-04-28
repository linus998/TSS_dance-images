<?php
/**
 * TSS_process copy.php
 * Seat assignment and order processing UI + AJAX handlers.
 * Clean-up: added comments, clearer mail flow and sent-flag handling.
 */

// ===============================================================
// ===== requirements =====
// ===============================================================
require_once 'AdminAccess.php';

// ===============================================================
// ===== Error Reporting Setup =====
// ===============================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/php_error.log');

// ===============================================================
// ===== Autoload Check ===== 
// ===============================================================
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
  die("autoload.php NOT found. Please upload vendor folder.<br>");
}
require_once $autoloadPath;

// ===============================================================
// ===== Environment Setup =====
// ===============================================================
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

$dotenvPath = realpath(__DIR__ . '/../Static');
if ($dotenvPath === false) {
  die("ERROR: Static folder not found.");
}

$dotenv = Dotenv::createImmutable($dotenvPath);
$dotenv->load();

// ===============================================================
// ===== Database Connection =====
// ===============================================================
try {
  $dbPath = $_ENV['TSS_DB_PATH'];
  $db = new PDO("sqlite:$dbPath");
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  die("Database error.");
}

// ===============================================================
// ===== AJAX Handler =====
// ===============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  $data = json_decode(file_get_contents('php://input'), true);

  if (!$data || !isset($data['order_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
  }

  $orderId = (int) $data['order_id'];

  // === Send Mail Action ===
  if ($data['action'] === 'send_mail') {
    $stmt = $db->prepare("SELECT * FROM t_orders WHERE order_ID = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || (int) $order['order_Completed'] !== 1) {
      echo json_encode(['success' => false, 'error' => 'Bestelling niet gevonden of niet compleet.']);
      exit;
    }

    // Email info
    $to = $order['order_Mail'];
    $name = htmlspecialchars($order['order_Name']);
    $total_cost = number_format($order['order_totalCosts'], 2);
    $prefseats = htmlspecialchars($order['order_PrefSeats'] ?? '');
    $seats = htmlspecialchars($order['order_AssignedSeats'] ?? '');

    // Map numeric show id to human-readable label
    $show_id = (string) ($order['order_Show'] ?? '');
    switch ($show_id) {
      case '1': $show_label = 'Zaterdag 13h'; break;
      case '2': $show_label = 'Zaterdag 16h30'; break;
      case '3': $show_label = 'Zondag 13h'; break;
      default:  $show_label = 'Zondag 16h30';
    }

    $adults = (int) $order['order_Adults'];
    $kids = (int) $order['order_Kids'];

    $summary_html = "
          <tr>
            <td style='text-align:center; padding:8px; border:1px solid #ddd;'>$show_label</td>
            <td style='text-align:center; padding:8px; border:1px solid #ddd;'>$adults</td>
            <td style='text-align:center; padding:8px; border:1px solid #ddd;'>$kids</td>
            <td style='text-align:center; padding:8px; border:1px solid #ddd;'>$prefseats</td>
            <td style='text-align:center; padding:8px; border:1px solid #ddd;'>€$total_cost</td>
          </tr>
        ";

    // Prepare and send confirmation email (PHPMailer)
    $mail = new PHPMailer(true);
    try {
      $mail->isSMTP();
      $mail->Host = $_ENV['MAIL_HOST'];
      $mail->SMTPAuth = true;
      $mail->Username = $_ENV['MAIL_USERNAME'];
      $mail->Password = $_ENV['MAIL_PASSWORD'];
      $mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'];
      $mail->Port = (int) $_ENV['MAIL_PORT'];

      $mail->setFrom($_ENV['MAIL_FROM'], $_ENV['MAIL_FROM_NAME']);
      $mail->addAddress($to, $name);

      $qrData = urlencode("Order ID: $orderId\nName: $name\nShow: $show_label\nSeats: $seats\nTest: True");
      $qrImageUrl = "https://services.dance-images.be/TSS_generateQR.php?data=" . $qrData;

      $mail->isHTML(true);
      $mail->Subject = 'Bevestiging TSS Ticket Bestelling';
      $mail->Body = "
            <html>
              <head>
                <meta charset='UTF-8'>
              </head>
              <body>
                <div style='font-family:Segoe UI, sans-serif; background:#f9f9f9; padding:20px; color:#333;'>
                  <div style='max-width:600px; margin:auto; background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1);'>
                    <h2 style='color:#1976d2; margin-top:0;'>&#127903; Bevestiging van je TSS-ticket bestelling</h2>
                    <p style='font-size:16px;'>Beste <strong>$name</strong>,</p>
                    <p style='font-size:15px;'>Dank voor je bestelling! Hieronder vind je het overzicht:</p>

                    <table style='width:100%; border-collapse:collapse; margin-top:20px; font-size:14px;'>
                      <thead>
                        <tr style='background-color:#f0f0f0; color:#444;'>
                          <th style='padding:8px; border:1px solid #ddd; text-align:center;'>Voorstelling</th>
                          <th style='padding:8px; border:1px solid #ddd; text-align:center;'>Volw.</th>
                          <th style='padding:8px; border:1px solid #ddd; text-align:center;'>Kind.</th>
                          <th style='padding:8px; border:1px solid #ddd; text-align:center;'>Zitplaats</th>
                          <th style='padding:8px; border:1px solid #ddd; text-align:center;'>Subtotaal</th>
                        </tr>
                      </thead>
                      <tbody>
                        $summary_html
                      </tbody>
                    </table>

                    <p style='font-size:16px; margin-top:20px;'><strong>Totale kost: &euro;$total_cost</strong></p>

                    <div style='margin-top:30px; text-align:center;'>
                      <p style='font-size:14px;'>Zitplaatsen: <strong>$seats</strong></p>
                      <img src=\"$qrImageUrl\">
                    </div>
                  </div>
                </div>
              </body>
            </html>";

      $mail->send();
      echo json_encode(['success' => true]);

      // Mark order as sent in database (non-fatal)
      
      try {
        $stmtSent = $db->prepare("UPDATE t_orders SET order_Sent = 1 WHERE order_ID = ?");
        $stmtSent->execute([$orderId]);
      } catch (Exception $e) {
        // ignore DB errors here; email already sent
      }

    } catch (Exception $e) {
      echo json_encode(['success' => false, 'error' => "Mailer Error"]);
    }
    exit;
  }

  // === Reset Action ===
  if ($data['action'] === 'reset_order' || (isset($data['seats']) && empty($data['seats']))) {
    $stmt = $db->prepare("SELECT order_AssignedSeats FROM t_orders WHERE order_ID = ?");
    $stmt->execute([$orderId]);
    $assigned = $stmt->fetchColumn();

    if ($assigned) {
      $labels = array_map('trim', explode(',', $assigned));
      $placeholders = implode(',', array_fill(0, count($labels), '?'));
      $stmt = $db->prepare("UPDATE t_seating SET Seating_IsTaken = 0, Seating_AssignedTo = NULL WHERE seating_Label IN ($placeholders)");
      $stmt->execute($labels);
    }

    // reset completed, assigned seats and clear sent-flag
    $stmt = $db->prepare("UPDATE t_orders SET order_Completed = 0, order_Sent = 0, order_AssignedSeats = NULL WHERE order_ID = ?");
    $stmt->execute([$orderId]);

    echo json_encode(['success' => true, 'reset' => true]);
    exit;
  }

  // === Normal Completion ===
  if (!isset($data['seats']) || !is_array($data['seats']) || empty($data['seats'])) {
    echo json_encode(['success' => false, 'error' => 'No seats provided']);
    exit;
  }

  $seatLabels = array_map('trim', $data['seats']);

  // Before executing update, get the show ID of the current order
  $stmtShow = $db->prepare("SELECT order_Show FROM t_orders WHERE order_ID = ?");
  $stmtShow->execute([$orderId]);
  $show = $stmtShow->fetchColumn();

  if ($show === false) {
    echo json_encode(['success' => false, 'error' => 'Show not found for order']);
    exit;
  }

  $placeholders = implode(',', array_fill(0, count($seatLabels), '?'));
  $sql = "UPDATE t_seating SET Seating_IsTaken = 1, Seating_AssignedTo = ? WHERE seating_Label IN ($placeholders) AND seating_Show = ?";
  $stmt = $db->prepare($sql);
  $stmt->execute(array_merge([$orderId], $seatLabels, [$show]));


  $joinedLabels = implode(',', $seatLabels);
  $stmt = $db->prepare("UPDATE t_orders SET order_Completed = 1, order_AssignedSeats = ? WHERE order_ID = ?");
  $stmt->execute([$joinedLabels, $orderId]);

  echo json_encode(['success' => true, 'reset' => false]);
  exit;
}

// ===============================================================
// ===== GET request: Page render =====
// ===============================================================
$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : null;

$orders = $db->query("SELECT * FROM t_orders ORDER BY order_ID ASC")->fetchAll(PDO::FETCH_ASSOC);

$order = null;
$seats = [];
$grouped = [];
$max_col = 0;
$totalSeatsRequired = 0;
$assigned_labels = [];
$pref_type = '';
$preferred_labels = [];
$orderCompleted = 0;

if ($order_id) {
  $stmt = $db->prepare("SELECT * FROM t_orders WHERE order_ID = ?");
  $stmt->execute([$order_id]);
  $order = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($order) {
    $assigned_labels = array_filter(array_map('trim', explode(',', $order['order_AssignedSeats'] ?? '')));
    $pref_raw = array_filter(explode(',', $order['order_PrefSeats'] ?? ''));
    $pref_type = ucfirst(array_shift($pref_raw) ?? '');
    $preferred_labels = array_map('trim', $pref_raw);
    $order_Message = $order['order_Message'] ?? '';

    // Calculate how many selectable seats are required for this order
    $totalSeatsRequired =
      (int) $order['order_Adults'] +
      (int) $order['order_Kids'] -
      (int) $order['order_Wheelchair'];

    $show_id = $order['order_Show'];
    $stmt = $db->prepare("SELECT * FROM t_seating WHERE seating_Show = ?");
    $stmt->execute([$show_id]);
    $seats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $orderCompleted = (int) ($order['order_Completed'] ?? 0);

    // Group seats by row/col for rendering in the seat grid
    foreach ($seats as $s) {
      $max_col = max($max_col, (int) $s['seating_Col']);
      $r = $s['seating_Row'];
      $c = (int) $s['seating_Col'];
      $grouped[$r][$c] = $s;
    }
    ksort($grouped);
  }
}
?>

<!-- Page: Seat assignment / processing UI -->
<script>
  const orderCompleted = <?= $orderCompleted ?>;
</script>

<!DOCTYPE html>
<html lang="nl">

<head>
  <meta charset="UTF-8" />
  <title>Seat Viewer</title>
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
      --blue-dark: #085da3;
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
      height: 100vh;
      overflow-x: hidden;
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

    #topbar button.action-btn {
      background-color: #e53935;
      /* red */
    }

    #topbar button.action-btn:hover {
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

    .modal {
      display: none;
      position: fixed;
      z-index: 1500;
      inset: 0;
      background-color: rgba(0, 0, 0, 0.6);
    }

    .modal-content {
      background: var(--glass-bg);
      backdrop-filter: blur(15px);
      margin: 80px auto;
      padding: 24px 30px;
      border-radius: 20px;
      width: 400px;
      max-height: 80vh;
      overflow-y: auto;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.6);
      color: var(--text-light);
    }

    .close {
      float: right;
      font-size: 28px;
      cursor: pointer;
      color: var(--text-light);
      user-select: none;
    }

    .completed-order {
      margin: 10px 0;
      padding: 12px 16px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 16px;
      border: 1.5px solid var(--border-glass);
      display: flex;
      justify-content: space-between;
      align-items: center;
      color: var(--text-light);
    }

    .action-btn {
      background: var(--red);
      border: none;
      padding: 8px 14px;
      border-radius: 12px;
      color: white;
      cursor: pointer;
      font-weight: 600;
      transition: background 0.3s ease;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.4);
    }

    .action-btn:hover {
      background: #f4511e;
    }

    #sidebar {
      width: 280px;
      background: var(--glass-bg);
      backdrop-filter: blur(15px);
      overflow-y: auto;
      padding: 20px;
      box-shadow: 4px 0 12px rgba(0, 0, 0, 0.6);
      border-radius: 20px 0 0 20px;
      scrollbar-width: none;
      /* Firefox */
      -ms-overflow-style: none;
      /* IE 10+ */
    }

    #sidebar::-webkit-scrollbar {
      display: none;
      /* Chrome, Safari, Opera */
    }

    .order {
      padding: 14px 16px;
      margin-bottom: 12px;
      background: rgba(255, 255, 255, 0.1);
      border: 1.5px solid var(--border-glass);
      border-radius: 16px;
      cursor: pointer;
      user-select: none;
      font-weight: 600;
      transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease;
      color: var(--text-light);
    }

    .order:hover {
      background: rgba(0, 198, 255, 0.2);
      border-color: var(--primary);
      color: white;
    }

    .order.completed {
      background-color: var(--yellow);
      color: white;
      border-color: var(--yellow-dark);
    }

    .order.selected {
      background-color: var(--green);
      color: white;
      border-color: var(--green-dark);
    }

    .order.send {
      background-color: var(--red);
      color: white;
      border-color: var(--red-dark);
    }

    #main {
      flex-grow: 1;
      padding: 24px 30px;
      overflow-y: auto;
      color: var(--text-light);
    }

    #seats {
      display: flex;
      flex-direction: column;
      gap: 10px;
      user-select: none;
      margin-top: 10px;
    }

    .seat-row {
      display: flex;
      gap: 10px;
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

    .seat:hover:not(.taken):not(.selected) {
      background-color: rgba(0, 198, 255, 0.3);
      border-color: var(--primary);
      box-shadow: 0 0 8px var(--primary);
    }

    .seat.selected {
      background-color: var(--green);
      border-color: var(--green-dark);
      color: white;
      box-shadow: 0 0 12px var(--green);
    }

    .seat.taken {
      background-color: var(--red);
      color: white;
      cursor: not-allowed;
      pointer-events: none;
      border-color: var(--red-dark);
      box-shadow: none;
    }

    .seat.preferred {
      border-color: var(--blue);
      box-shadow: 0 0 10px var(--blue);
    }

    .seat.empty {
      background: transparent;
      border: none;
      pointer-events: none;
      box-shadow: none;
      cursor: default;
    }

    .row-separator {
      height: 20px;
      width: 100%;
      border-top: 2px dashed var(--primary);
      margin: 10px 0;
    }
  </style>

</head>

<body>
  <div id="topbar">
    <div>
      <button onclick="resetOrder(<?= json_encode($order_id) ?>)" class="action-btn">Reset</button>
      <button onclick="openResetModal()" class="action-btn">Toon voltooide bestellingen</button>
      <button onclick="sendMail(<?= json_encode($order_id) ?>)" class="action-btn">Send Mail</button>
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
  <div id="resetModal" class="modal">
    <div class="modal-content">
      <span class="close" onclick="closeResetModal()">&times;</span>
      <h3>Voltooide bestellingen</h3>
      <div id="completedOrdersList">
        <?php
        $stmt = $db->query("SELECT order_ID, order_Name, order_AssignedSeats FROM t_orders WHERE order_Completed = 1 ORDER BY order_ID ASC");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $completed) {
          echo "<div class='completed-order'>";
          echo "<strong>#{$completed['order_ID']}</strong> – " . htmlspecialchars($completed['order_Name']);
          echo " <button class='action-btn' onclick='resetOrder({$completed['order_ID']})'>Reset</button>";
          echo "</div>";
        }
        ?>
      </div>
    </div>
  </div>

  <div id="sidebar">
    <h2>Bestellingen</h2>
    <?php
    foreach ($orders as $o) {
      $classes = ['order'];
      if ($o['order_Sent']) {
        // CSS uses class 'send' for sent styling
        $classes[] = 'send';
      } elseif ($o['order_Completed']) {
        $classes[] = 'completed';
      }
      elseif ($o['order_ID'] === $order_id) {
        $classes[] = 'selected';
      }
      $class_str = implode(' ', $classes);

      $kids = (int) $o['order_Kids'];
      $total = (int) $o['order_Adults'] + $kids + (int) $o['order_Wheelchair'];
      echo "<div class='$class_str' onclick='goToOrder({$o['order_ID']})'>";
      echo "<strong>Order #" . $o['order_ID'] . "</strong><br>";
      echo "Show: " . htmlspecialchars($o['order_Show']) . "<br>";
      echo htmlspecialchars($o['order_Name']) . "<br>";
      echo "Voorkeur: " . htmlspecialchars($o['order_PrefSeats']) . "<br>";
      echo "Totaal tickets: " . htmlspecialchars($total) . "<br>";
      echo "Tot price: &euro;<strong>" . htmlspecialchars($o['order_totalCosts']) . "</strong>";
      echo "</div>";
    }
    ?>
  </div>

  <div id="main">
    <?php if ($order): ?>
      <h2>Order #<?= htmlspecialchars($order_id) ?></h2>
      <p>
        <strong>Voorkeur:</strong> <?= htmlspecialchars($pref_type) ?>
        <?php if (!empty($order_Message)): ?>
          &nbsp;&nbsp;|&nbsp;&nbsp;
          <strong>Bericht:</strong> <?= htmlspecialchars($order_Message) ?>
        <?php endif; ?>
      </p>
      <p><strong>Totaal stoelen:</strong> <?= htmlspecialchars($totalSeatsRequired) ?></p>
      <div id="totalSeatsRequired" style="display:none;"><?= $totalSeatsRequired ?></div>

      <div id="seats">
        <?php 
        $rowIndex = 0;
        foreach ($grouped as $row => $cols): 
          $rowIndex++;
        ?>
          <div class="seat-row">
            <?php
            for ($col = 1; $col <= $max_col; $col++) {
              if (isset($cols[$col])) {
                $seat = $cols[$col];
                $label = $seat['seating_Label'];
                $isTaken = (int) $seat['Seating_IsTaken'];
                $assigned = $seat['Seating_AssignedTo'];

                $classes = ['seat'];
                if (in_array($label, $assigned_labels)) {
                  $classes[] = 'selected';
                } elseif ($isTaken) {
                  $classes[] = 'taken';
                }

                // Mark preferred seats with border highlight if preferred
                if (in_array($label, $preferred_labels)) {
                  $classes[] = 'preferred';
                }

                $class_str = implode(' ', $classes);

                echo "<div id='seat_{$label}' class='$class_str' onclick='toggleSeat(\"$label\")'>$label</div>";
              } else {
                echo "<div class='seat empty'></div>";
              }
            }
            ?>
          </div>
          <?php if ($rowIndex == 3 || $rowIndex == 6|| $rowIndex == 9): ?>
            <div class="row-separator"></div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

    <?php else: ?>
      <p>Selecteer een bestelling links om stoelen te bekijken.</p>
    <?php endif; ?>
  </div>

  <script>
    let selectedSeats = new Set();

    const totalSeatsRequired = parseInt(document.getElementById('totalSeatsRequired')?.textContent) || 0;

    // Restore sidebar scroll position on page load
    window.addEventListener('load', () => {
      const scrollTop = sessionStorage.getItem('sidebarScroll');
      if (scrollTop !== null) {
        document.getElementById('sidebar').scrollTop = parseInt(scrollTop, 10);
      }
    });

    // Save sidebar scroll before navigating to another order
    function goToOrder(orderId) {
      const sidebar = document.getElementById('sidebar');
      sessionStorage.setItem('sidebarScroll', sidebar.scrollTop);
      window.location.href = '?order_id=' + orderId;
    }

    // Toggle a single seat selection on/off
    function toggleSeat(label) {
      if (orderCompleted) {
        alert("Deze bestelling is voltooid, er kunnen geen stoelen meer geselecteerd worden.");
        return;
      }

      const el = document.getElementById('seat_' + label);
      if (!el || el.classList.contains('empty') || el.classList.contains('taken')) return;

      if (selectedSeats.has(label)) {
        selectedSeats.delete(label);
        el.classList.remove('selected');
      } else {
        if (selectedSeats.size < totalSeatsRequired) {
          selectedSeats.add(label);
          el.classList.add('selected');

          if (selectedSeats.size === totalSeatsRequired) {
            setTimeout(() => {
              if (confirm("U hebt het juiste aantal stoelen geselecteerd. Wilt u de bestelling voltooien?")) {
                completeOrder();
              }
            }, 50);
          }
        } else {
          alert("U hebt al het vereiste aantal stoelen geselecteerd.");
        }
      }
    }

    // Send selected seats to server to complete the order
    function completeOrder() {
      const orderId = <?= json_encode($order_id) ?>;
      if (!orderId) {
        alert("Geen bestelling geselecteerd.");
        return;
      }
      const seats = Array.from(selectedSeats);

      fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order_id: orderId, seats: seats })
      })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            window.location.reload();
          } else {
            alert("Fout bij voltooien bestelling: " + (data.error || "Onbekende fout"));
          }
        })
        .catch(() => {
          alert("Fout bij verbinden met server.");
        });
    }

    // Modal helpers
    function openResetModal() {
      document.getElementById('resetModal').style.display = 'block';
    }
    function closeResetModal() {
      document.getElementById('resetModal').style.display = 'none';
    }

    // Reset an order: free seats and clear completed/sent flags
    function resetOrder(orderId) {
      if (!confirm('Weet je zeker dat je deze bestelling wilt resetten?')) return;

      fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          order_id: orderId,
          seats: []  // lege seats-array triggert reset
        })
      })
        .then(res => res.json())
        .then(data => {
          if (data.success && data.reset) {
            alert('Bestelling succesvol gereset.');
            location.reload();  // Pagina herladen, of UI dynamisch updaten
          } else {
            alert('Fout bij resetten: ' + (data.error || 'Onbekende fout'));
          }
        })
        .catch(err => {
          console.error(err);
          alert('Netwerkfout bij resetten.');
        });
    }

    // Trigger server to send confirmation email for an order
    function sendMail(orderId) {
      if (!orderId) return alert('Geen bestelling geselecteerd.');

      fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'send_mail',
          order_id: orderId
        })
      })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            alert('E-mail succesvol verzonden!');
            window.location.reload();
          } else {
            alert('Fout bij verzenden e-mail: ' + (data.error || 'Onbekende fout'));
          }
        })
        .catch(err => {
          console.error(err);
          alert('Netwerkfout bij verzenden e-mail.');
        });
    }
  </script>
</body>

</html>