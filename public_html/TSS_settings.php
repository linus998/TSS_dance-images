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

// ...existing code...
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

// ===== Settings Logic =====
$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Fix for checkbox: if not set, treat as 0
  if (!isset($_POST['UseEarlyBird'])) {
    $_POST['UseEarlyBird'] = '0';
  }
  // Save settings
  foreach ($_POST as $name => $value) {
    if ($name === 'save_settings')
      continue;
    $stmt = $db->prepare("UPDATE t_settings SET settings_value = :value WHERE settings_name = :name");
    $stmt->execute([
      ':value' => $value,
      ':name' => $name
    ]);
  }
  $message = "Settings saved!";
}

// Fetch settings
$settings = [];
$stmt = $db->query("SELECT settings_name, settings_value FROM t_settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $settings[$row['settings_name']] = $row['settings_value'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Settings</title>
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

  <div
    style="max-width: 1700px; margin: 60px auto 0 auto; background: var(--glass-bg); padding: 32px; border-radius: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.3);">
    <h2 style="color: var(--primary); margin-bottom: 18px;">System Settings</h2>
    <?php if ($message): ?>
      <div style="color: var(--green); margin-bottom: 12px; font-weight: 600;"> <?= htmlspecialchars($message) ?> </div>
    <?php endif; ?>
    <form method="post">
      <div
        style="display: flex; flex-direction: row; gap: 32px; align-items: center; flex-wrap: nowrap; width: 100%; overflow-x: auto;">
        <?php foreach ($settings as $name => $value): ?>
          <div style="display: flex; align-items: center; gap: 8px; min-width: 220px;">
            <label for="<?= htmlspecialchars($name) ?>"
              style="font-weight: 600; color: var(--text-light); min-width: 120px; text-align: right;">
              <?= htmlspecialchars($name) ?>
            </label>
            <?php if (strtolower($name) === 'useearlybird'): ?>
              <label class="switch">
                <input type="checkbox" name="<?= htmlspecialchars($name) ?>" id="<?= htmlspecialchars($name) ?>" value="1"
                  <?= $value == '1' ? 'checked' : '' ?> />
                <span class="slider"></span>
              </label>
            <?php else: ?>
              <input type="number" step="any" name="<?= htmlspecialchars($name) ?>" id="<?= htmlspecialchars($name) ?>"
                value="<?= htmlspecialchars($value) ?>" style="width: 80px; padding: 8px; border-radius: 8px;" />
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <button type="submit" name="save_settings"
          style="background: var(--primary); color: white; padding: 10px 24px; border-radius: 10px; font-weight: 600; border: none; cursor: pointer; min-width: 140px;">Save
          Settings</button>
      </div>
    </form>
  </div>

  <style>
    .switch {
      position: relative;
      display: inline-block;
      width: 50px;
      height: 28px;
    }

    .switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: #ccc;
      transition: .4s;
      border-radius: 28px;
    }

    .slider:before {
      position: absolute;
      content: "";
      height: 22px;
      width: 22px;
      left: 3px;
      bottom: 3px;
      background-color: white;
      transition: .4s;
      border-radius: 50%;
    }

    input:checked+.slider {
      background-color: var(--primary);
    }

    input:checked+.slider:before {
      transform: translateX(22px);
    }
  </style>
</body>

</html>