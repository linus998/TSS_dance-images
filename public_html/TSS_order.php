<?php
// Show errors only in logs, not displayed publicly
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Composer autoloader
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
  require_once $autoloadPath;
} else {
  error_log("autoload.php NOT found. Make sure you uploaded /vendor correctly.");
  http_response_code(500);
  exit("Internal Server Error");
}

// Use PHPMailer namespaces
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load environment variables with PHP Dotenv
$dotenvPath = realpath(__DIR__ . '/../Static');
if (!$dotenvPath) {
  error_log("Path to dotenv folder does not exist.");
  http_response_code(500);
  exit("Internal Server Error");
}

$envFile = $dotenvPath . '/.env';

if (file_exists($envFile)) {
  $dotenv = Dotenv\Dotenv::createImmutable($dotenvPath);
  $dotenv->load();

  // Validate critical variables
  $requiredVars = [
    'MAIL_HOST',
    'MAIL_PORT',
    'MAIL_ENCRYPTION',
    'MAIL_USERNAME',
    'MAIL_PASSWORD',
    'MAIL_FROM',
    'MAIL_FROM_NAME'
  ];

  foreach ($requiredVars as $var) {
    if (empty($_ENV[$var])) {
      error_log("$var not set in .env");
      http_response_code(500);
      exit("Internal Server Error");
    }
  }
} else {
  error_log(".env file not found at $envFile");
  http_response_code(500);
  exit("Internal Server Error");
}

// Start session
session_start();

// SETTINGS
$shows = [
  'za13' => 'Zaterdag 13:00',
  'za16h30' => 'Zaterdag 16:30',
  'zo13' => 'Zondag 13:00',
  'zo16h30' => 'Zondag 16:30'
];
$show_numbers = [
  'za13' => 1,
  'za16h30' => 2,
  'zo13' => 3,
  'zo16h30' => 4
];
$seatOptions = [
  'hoog' => 'Tribune hoog',
  'midden' => 'Tribune midden',
  'laag' => 'Tribune laag',
  'losse' => 'Losse stoelen (&🦽)'
];

$dbPath = $_ENV['TSS_DB_PATH'];
$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Fetch prices and relevant settings
$stmt = $db->prepare("SELECT settings_name, settings_value FROM t_settings WHERE settings_name IN (
  'UseEarlyBird', 
  'PriceAdult_Early', 
  'PriceAdult_Regular', 
  'PriceChild_Early', 
  'PriceChild_Regular',
  'MaxTickets'
)");
$stmt->execute();
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Determine if early bird pricing applies
$useEarly = ($settings['UseEarlyBird'] ?? '0') === '1';

// Get correct prices, fallback to 0 if not set
$priceAdult = floatval($useEarly ? ($settings['PriceAdult_Early'] ?? 0) : ($settings['PriceAdult_Regular'] ?? 0));
$priceChild = floatval($useEarly ? ($settings['PriceChild_Early'] ?? 0) : ($settings['PriceChild_Regular'] ?? 0));

// Get max tickets, default to 600 if not set
$maxTickets = intval($settings['MaxTickets'] ?? 600);

function fetchSingle($db, $query) {
    return $db->query($query)->fetchColumn();
}

// ON SUBMIT
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['firstname'] ?? '') . ' ' . trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');


  if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error_message = "Naam of e-mail ongeldig.";
  }

  if (!isset($error_message)) {

    $totals = [];
    $excelData = [];

    foreach ($shows as $code => $label) {
      $adults = intval($_POST["adult_$code"] ?? 0);
      $children = intval($_POST["child_$code"] ?? 0);
      $seatpref = $_POST["seatpref_$code"] ?? '';
      $rolstoel = ($seatpref === 'losse') ? intval($_POST["rolstoel_num_$code"] ?? 0) : 0;
      $mededeling = trim($_POST["mededeling_text_$code"] ?? '');

      if ($adults + $children > 0 && $adults >= 0 && $children >= 0) {

        if($rolstoel > ($adults + $children)){
            $error_message = "Er kunnen niet meer rolstoelen dan tickets zijn.";
            break;
        }

        $show_number = $show_numbers[$code] ?? null;
        $sold_to_adult = fetchSingle($db, "SELECT SUM(order_Adults) FROM t_orders WHERE order_Show = " . $db->quote($show_number));
        $sold_to_children = fetchSingle($db, "SELECT SUM(order_Kids) FROM t_orders WHERE order_Show = " . $db->quote($show_number));
              
        $available_tickets = $maxTickets - ($sold_to_adult + $sold_to_children);

        if($adults + $children > $available_tickets){
          $error_message = "Er zijn niet meer zoveel tickets beschikbaar. (Nog $available_tickets beschikbaar)";
          break;        
        }
        $total = ($adults * $priceAdult) + ($children * $priceChild);
        $totals[$code] = $total;

        $excelData[$code][] = [
          'Naam' => $name,
          'Email' => $email,
          'Volwassenen' => $adults,
          'Kinderen' => $children,
          'Zitplaats' => $seatpref,
          'Rolstoel' => $rolstoel,
          'Totaal' => $total,
          'Mededeling' => $mededeling
        ];
      }
    }

    if (empty($excelData)) {
      if (!isset($error_message)) {
        $error_message = "Je moet minstens 1 ticket bestellen.";
      }
    }

    if (!isset($error_message)) {
      // Total cost
      $totalCost = array_sum($totals);
      $excelData['Total'][] = [
        'Naam' => $name,
        'Totaal' => $totalCost
      ];

      $db->exec("
        CREATE TABLE IF NOT EXISTS t_orders (
          order_ID INTEGER PRIMARY KEY AUTOINCREMENT,
          order_Name TEXT,
          order_Mail TEXT,
          order_Show NUMERIC,
          order_Adults INTEGER,
          order_Kids TEXT,
          order_PrefSeats TEXT,
          order_Wheelchair INTEGER,
          order_totalCosts REAL,
          order_Completed INTEGER,
          order_AssignedSeats INTEGER,
          order_Sent INTEGER,
          order_Message	TEXT
        )
      ");

      foreach ($excelData as $code => $rows) {
        if ($code === 'Total')
          continue;

        foreach ($rows as $row) {
          $voorstellingMap = [
            'za13' => 1,
            'za16h30' => 2,
            'zo13' => 3,
            'zo16h30' => 4
          ];
          $voorstelling = $voorstellingMap[$code] ?? 0;

          $stmt = $db->prepare("
            INSERT INTO t_orders (
              order_Name, order_Mail, order_Show, order_Adults, order_Kids,
              order_PrefSeats, order_Wheelchair, order_totalCosts,
              order_Completed, order_AssignedSeats, order_Sent, order_Message
            ) VALUES (
              :name, :email, :show, :adults, :kids, :seats, :wheel, :total, 0, 0, 0, :message
            )
          ");

          $stmt->execute([
            ':name' => $row['Naam'],
            ':email' => $row['Email'],
            ':show' => $voorstelling,
            ':adults' => $row['Volwassenen'],
            ':kids' => $row['Kinderen'],
            ':seats' => $row['Zitplaats'],
            ':wheel' => $row['Rolstoel'],
            ':total' => $row['Totaal'],
            ':message' => $row['Mededeling']
          ]);
        }
      }

      // Optional save total
      $db->exec("
        CREATE TABLE IF NOT EXISTS totals (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          naam TEXT,
          totaal REAL
        )
      ");
      $stmt = $db->prepare("INSERT INTO totals (naam, totaal) VALUES (:naam, :totaal)");
      $stmt->execute([':naam' => $name, ':totaal' => $totalCost]);

      // SEND EMAIL
      $mail = new PHPMailer(true);
      try {
        $mail->isSMTP();
        $mail->Host = $_ENV['MAIL_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['MAIL_USERNAME'];
        $mail->Password = $_ENV['MAIL_PASSWORD'];

        // New: read encryption and port from .env
        $mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'];
        $mail->Port = intval($_ENV['MAIL_PORT']);

        $mail->setFrom($_ENV['MAIL_FROM'], $_ENV['MAIL_FROM_NAME']);
        $mail->addAddress($email, $name);
        $mail->Subject = 'Bevestiging ticket bestelling';

        // Build email summary
        $summaryHtml = '';
        foreach ($excelData as $code => $entries) {
          if ($code === 'Total')
            continue;
          foreach ($entries as $entry) {
            $summaryHtml .= "
              <tr>
                <td style='padding: 8px; border: 1px solid #ccc;'>{$shows[$code]}</td>
                <td style='padding: 8px; border: 1px solid #ccc;'>{$entry['Volwassenen']}</td>
                <td style='padding: 8px; border: 1px solid #ccc;'>{$entry['Kinderen']}</td>
                <td style='padding: 8px; border: 1px solid #ccc;'>{$seatOptions[$entry['Zitplaats']]}</td>
                <td style='padding: 8px; border: 1px solid #ccc;'>&euro;{$entry['Totaal']}</td>
              </tr>";
          }
        }

        $htmlBody = "
        <html>
          <body style='font-family:Segoe UI, sans-serif; background:#f7f9fb; padding:30px; color:#333;'>
            <div style='max-width:600px; margin:auto; background:#fff; border-radius:10px; padding:30px; box-shadow:0 0 10px rgba(0,0,0,0.05);'>
              
              <h2 style='color:#2c89e8; margin-top:0;'>&#127903; Bevestiging van uw TSS-ticketbestelling</h2>
              
              <p style='font-size:15px;'>Beste <strong>$name</strong>,</p>
              <p style='font-size:15px;'>Bedankt voor uw bestelling! Hieronder vindt u een overzicht van uw gekozen voorstelling(en):</p>

              <table style='width:100%; border-collapse:collapse; margin-top:20px; font-size:14px;'>
                <thead style='background:#eaf3ff;'>
                  <tr>
                    <th style='padding:10px; border:1px solid #ccc; text-align:left;'>Voorstelling</th>
                    <th style='padding:10px; border:1px solid #ccc; text-align:left;'>Volw.</th>
                    <th style='padding:10px; border:1px solid #ccc; text-align:left;'>Kind.</th>
                    <th style='padding:10px; border:1px solid #ccc; text-align:left;'>Zitplaats(en)</th>
                    <th style='padding:10px; border:1px solid #ccc; text-align:left;'>Subtotaal</th>
                  </tr>
                </thead>
                <tbody>
                  $summaryHtml
                </tbody>
              </table>

              <p style='margin-top:20px; font-size:16px;'><strong>&#128462; Totale kost: &euro;$totalCost</strong></p>

              <p style='margin-top:30px; font-size:15px;'>We kijken ernaar uit u te verwelkomen tijdens de voorstelling!</p>

              <hr style='margin:30px 0; border:none; border-top:1px solid #eee;'>

              <p style='font-size:13px; color:#888;'>Hebt u vragen? U kunt eenvoudig antwoorden op deze e-mail.</p>
            </div>
          </body>
        </html>";


        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->send();
      } catch (Exception $e) {
        error_log("Mail error: " . $mail->ErrorInfo);
        $error_message = "E-mail kon niet verzonden worden.";
      }

      if (!isset($error_message)) {
        $_SESSION['success'] = true;
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
      }
    }
  }
}

?>

<!DOCTYPE html>
<html lang="nl">

<head>
  <meta charset="UTF-8" />
  <title>Bestel Tickets – TSS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap');

    :root {
      --primary: #00c6ff;
      --primary-dark: #0072ff;
      --bg-gradient: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
      --glass-bg: rgba(255, 255, 255, 0.1);
      --text-light: #eee;
      --text-dark: #ddd;
      --border-glass: rgba(255, 255, 255, 0.2);
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
      padding: 40px 20px;
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: flex-start;
      overflow-x: hidden;
    }

    .container {
      background: var(--glass-bg);
      backdrop-filter: blur(15px);
      border-radius: 20px;
      padding: 40px 30px;
      max-width: 900px;
      width: 100%;
      box-shadow: 0 0 20px rgba(0, 0, 0, 0.4);
      box-sizing: border-box;
      animation: fadeIn 1s ease-out;
      color: var(--text-light);
    }

    h2 {
      text-align: center;
      margin-bottom: 30px;
      font-weight: 600;
      font-size: 2.2rem;
      color: var(--primary);
    }

    label {
      display: block;
      font-weight: 600;
      font-size: 1rem;
      margin-top: 12px;
      margin-bottom: 6px;
      color: var(--text-light);
    }

    input,
    select {
      width: 100%;
      padding: 12px 15px;
      border-radius: 12px;
      border: 1px solid var(--border-glass);
      background: rgba(255, 255, 255, 0.15);
      color: var(--text-light);
      font-size: 1rem;
      transition: background 0.3s ease, border-color 0.3s ease;
    }

    input::placeholder,
    select::placeholder {
      color: var(--text-dark);
    }

    input:focus,
    select:focus {
      outline: none;
      background: rgba(255, 255, 255, 0.3);
      border-color: var(--primary);
    }

    .show {
      background-color: rgba(234, 243, 255, 0.15);
      padding: 20px;
      margin-bottom: 25px;
      border-radius: 16px;
      border: 1px solid var(--border-glass);
      color: var(--text-light);
    }

    .show h3 {
      margin-top: 0;
      color: var(--primary);
      font-size: 1.3rem;
    }

    button {
      width: 100%;
      padding: 14px;
      border: none;
      border-radius: 12px;
      background: var(--primary);
      color: white;
      font-size: 1.25rem;
      font-weight: 600;
      cursor: pointer;
      margin-top: 20px;
      transition: background 0.3s ease;
    }

    button:hover,
    button:focus {
      background: var(--primary-dark);
      outline: none;
    }

    .rolstoel-container {
      margin-top: 10px;
      padding: 15px;
      background-color: rgba(255, 255, 255, 0.05);
      border-radius: 12px;
      border: 1px solid var(--border-glass);
      animation: fadeIn 0.4s ease-out;
    }

    .rolstoel-container label {
      margin-top: 0;
    }


    #summary {
      background: rgba(234, 243, 255, 0.15);
      padding: 20px;
      border-radius: 16px;
      font-family: monospace;
      white-space: pre-line;
      margin-top: 30px;
      border: 1px dashed var(--border-glass);
      font-size: 1rem;
      color: var(--text-light);
    }

    .message {
      padding: 15px 20px;
      border-radius: 16px;
      margin-bottom: 25px;
      font-size: 1rem;
      color: var(--text-light);
    }

    .success {
      background-color: rgba(231, 249, 231, 0.3);
      border: 1px solid #a3e4a3;
      color: #ffffffff;
    }

    .error {
      background-color: rgba(244, 67, 54, 0.3);
      border: 1px solid #f44336;
      color: #fff;
    }

    .inline-group {
      display: flex;
      flex-wrap: wrap;
      gap: 20px;
      align-items: flex-end;
    }

    .inline-group>div {
      flex: 2;
      min-width: 180px;
    }

    /* Mobile-specific styles */
    @media (max-width: 600px) {
      body {
        padding: 20px 15px;
        align-items: center;
      }

      .container {
        padding: 30px 20px;
        border-radius: 16px;
        box-shadow: 0 0 15px rgba(0, 0, 0, 0.3);
      }

      h2 {
        font-size: 1.8rem;
        margin-bottom: 20px;
      }

      label {
        font-size: 0.95rem;
      }

      input,
      select {
        font-size: 1rem;
        padding: 12px;
      }

      button {
        font-size: 1.1rem;
        padding: 12px;
      }

      .inline-group {
        flex-direction: column;
        gap: 15px;
      }

      .inline-group>div {
        min-width: 100%;
      }

      #summary {
        font-size: 0.95rem;
        padding: 15px;
        margin-top: 20px;
      }
    }

    select {
      width: 100%;
      min-width: 180px;
      max-width: 100%;
      padding: 12px 15px;
      padding-right: 3rem;
      border-radius: 12px;
      border: 1px solid var(--border-glass);
      background: rgba(255, 255, 255, 0.15);
      color: var(--text-light);
      font-size: 1rem;
      transition: background 0.3s ease, border-color 0.3s ease;
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      cursor: pointer;
      background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%23ddd' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 1rem center;
      background-size: 1.2em;
      box-sizing: border-box;
    }

    select:focus {
      outline: none;
      background: rgba(255, 255, 255, 0.25);
      border-color: var(--primary);
    }

    select option {
      background-color: #2c3e50;
      color: var(--text-light);
    }

    option {
      background-color: #1a1a1a;
      color: #eee;
    }

    @keyframes fadeIn {
      from {
        transform: scaleY(0.95);
        opacity: 0;
      }

      to {
        transform: scaleY(1);
        opacity: 1;
      }
    }

    .mededeling-container {
      margin-top: 15px;
    }
  </style>
</head>

<body>
  <div class="container">
    <h2>🎟️ Ticket Bestellen</h2>

    <?php if (isset($error_message)): ?>
      <div class="message error">
        ❌ <?php echo htmlspecialchars($error_message); ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['success'])): ?>
      <div class="message success">
        ✅ Verificatie email succesvol verzonden, bedankt voor de bestelling.
      </div>
      <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <form method="POST">
      <div class="inline-group" style="margin-bottom: 30px;">
        <div>
          <label for="firstname">Voornaam</label>
          <input type="text" name="firstname" id="firstname" value="<?php echo htmlspecialchars($_POST['firstname'] ?? ''); ?>" required />
        </div>
        <div>
          <label for="name">Naam</label>
          <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required />
        </div>
      </div>

      <label for="email">Email</label>
      <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required style="margin-bottom: 30px;" />

      <?php foreach ($shows as $code => $label): ?>
        <div class="show">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <h3><?= $label ?></h3>
            <label style="font-size: 0.9rem;">
              <input type="checkbox" name="mededeling_checkbox_<?= $code ?>" onchange="toggleMededeling(this, '<?= $code ?>')" <?php if (isset($_POST["mededeling_checkbox_$code"])) echo 'checked'; ?> />
              Mededeling
            </label>
          </div>

          <div class="inline-group">
            <div>
              <label for="adult_<?= $code ?>">Volwassenen</label>
              <input type="number" name="adult_<?= $code ?>" id="adult_<?= $code ?>" value="<?php echo htmlspecialchars($_POST["adult_$code"] ?? '0'); ?>" min="0" />
            </div>
            <div>
              <label for="child_<?= $code ?>">Kinderen (tot 12 jaar)</label>
              <input type="number" name="child_<?= $code ?>" id="child_<?= $code ?>" value="<?php echo htmlspecialchars($_POST["child_$code"] ?? '0'); ?>" min="0" />
            </div>
            <div>
              <label for="seatpref_<?= $code ?>">Zitplaats</label>
              <select name="seatpref_<?= $code ?>" id="seatpref_<?= $code ?>"
                onchange="toggleRolstoel(this, '<?= $code ?>')">
                <?php foreach ($seatOptions as $key => $val): ?>
                  <option value="<?= $key ?>" <?php if (($_POST["seatpref_$code"] ?? '') === $key) echo 'selected'; ?>><?= $val ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div id="rolstoel_<?= $code ?>" class="rolstoel-container" style="display: <?php echo (($_POST["seatpref_$code"] ?? '') === 'losse') ? 'block' : 'none'; ?>;">
              <label for="rolstoel_num_<?= $code ?>">Rolstoel</label>
              <input type="number" name="rolstoel_num_<?= $code ?>" id="rolstoel_num_<?= $code ?>" value="<?php echo htmlspecialchars($_POST["rolstoel_num_$code"] ?? '0'); ?>" min="0" />
            </div>
          </div>
          <div id="mededeling_<?= $code ?>" class="mededeling-container" style="display: <?php echo isset($_POST["mededeling_checkbox_$code"]) ? 'block' : 'none'; ?>; width: 100%;">
            <label for="mededeling_text_<?= $code ?>">Mededeling</label>
            <textarea name="mededeling_text_<?= $code ?>" id="mededeling_text_<?= $code ?>" rows="3"
              placeholder="Typ hier een mededeling..."
              style="width: 100%; padding: 12px; border-radius: 12px; border: 1px solid var(--border-glass); background: rgba(255,255,255,0.1); color: var(--text-light); font-size: 1rem;"><?php echo htmlspecialchars($_POST["mededeling_text_$code"] ?? ''); ?></textarea>
          </div>
        </div>
      <?php endforeach; ?>

      <div id="summary"></div>
      <button type="submit">✅ Bestelling Verzenden</button>
    </form>
  </div>

  <script>

    function toggleMededeling(checkbox, code) {
      const box = document.getElementById('mededeling_' + code);
      if (checkbox.checked) {
        box.style.display = 'block';
      } else {
        box.style.display = 'none';
      }
    }

    function toggleRolstoel(select, code) {
      const div = document.getElementById('rolstoel_' + code);
      if (select.value === 'losse') {
        div.style.display = 'block';
      } else {
        div.style.display = 'none';
        div.querySelector('input').value = 0;
      }
    }

    document.querySelectorAll('input[type=number], select').forEach(el => {
      el.addEventListener('input', updateSummary);
      el.addEventListener('change', updateSummary);
    });

    function updateSummary() {
      const shows = <?= json_encode($shows) ?>;
      const adultPrice = <?= $priceAdult ?>;
      const childPrice = <?= $priceChild ?>;
      let summary = '';
      let total = 0;

      for (const code in shows) {
        const adults = parseInt(document.querySelector(`[name="adult_${code}"]`).value || 0);
        const children = parseInt(document.querySelector(`[name="child_${code}"]`).value || 0);
        if (adults + children === 0) continue;
        const cost = (adults * adultPrice) + (children * childPrice);
        summary += `${shows[code]}: ${adults} volw. × €${adultPrice}, ${children} kind × €${childPrice} = €${cost}\n`;
        total += cost;
      }

      if (summary) {
        summary += `\n🧾 Totale kost: €${total}`;
      }

      document.getElementById('summary').textContent = summary.trim();
    }

    // ✅ Ensure correct initial display of rolstoel fields on page load
    document.querySelectorAll('select[id^="seatpref_"]').forEach(select => {
      const code = select.id.replace('seatpref_', '');
      toggleRolstoel(select, code);
    });

    document.querySelectorAll('input[name^="mededeling_checkbox"]').forEach(checkbox => {
      const code = checkbox.name.replace('mededeling_checkbox_', '');
      toggleMededeling(checkbox, code);
    });

    updateSummary();
  </script>
</body>

</html>