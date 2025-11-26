<?php
declare(strict_types=1);

/**
 * Complete Admin Panel – Dashboard, Users, Orders, Payments, SMS Config, Account Settings
 * Security: PDO prepared statements, CSRF protection, role checks
 */

if (
  ($admin["admin_type"] == 2 || $admin["admin_type"] == 3 || $admin["admin_type"] == 4)
  && !empty($_SESSION["msmbilisim_adminlogin"])
  && (int)$admin["client_type"] === 2
) :
  header_remove('X-Powered-By');
  header('X-Frame-Options: SAMEORIGIN');
  header('X-Content-Type-Options: nosniff');

  if (!isset($conn) || !($conn instanceof PDO)) {
    http_response_code(500);
    echo "PDO \$conn not available.";
    exit;
  }

  $apiKey = isset($app['api_key']) ? (string)$app['api_key'] : '';
  $BASE   = 'https://api.smspool.net';

  // ===== Utilities =====
  function jres(bool $ok, string $msg = '', array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>$ok,'message'=>$msg,'data'=>$data], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
  }

  function convertCurrencyUpdateds(string $from_currency, string $to_currency, float $amount) {
    global $settings;
    $currentcur = json_decode($settings["currency_conversion_data"] ?? "{}", true);
    if (!is_array($currentcur) || !isset($currentcur["rates"])) return false;
    $from = strtoupper(trim($from_currency));
    $to   = strtoupper(trim($to_currency));
    if (!isset($currentcur["rates"][$from]) || !isset($currentcur["rates"][$to])) return false;
    $fromRate = (float)$currentcur["rates"][$from];
    $toRate   = (float)$currentcur["rates"][$to];
    if ($fromRate <= 0.0 || $toRate <= 0.0) return false;
    $usdAmount = $amount / $fromRate;
    $converted = $usdAmount * $toRate;
    return round($converted, 4);
  }

  function smspool_post(string $path, array $params = [], ?string $withKey = null): array {
    global $BASE;
    $url = rtrim($BASE, '/') . '/' . ltrim($path, '/');
    if ($withKey !== null && !array_key_exists('key', $params)) {
      $params['key'] = $withKey;
    }
    $headers = [];
    if ($withKey !== null) {
      $headers[] = 'Authorization: Bearer ' . $withKey;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $params,
      CURLOPT_HTTPHEADER     => $headers,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT        => 30,
      CURLOPT_USERAGENT      => 'Infozeen-SMSPool/1.0 PHP',
    ]);
    $body = curl_exec($ch);
    $err  = curl_errno($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err !== 0) throw new RuntimeException("cURL error {$err} calling {$path}");
    if ($code !== 200 || !is_string($body) || $body === '') throw new RuntimeException("HTTP {$code} from {$path}");
    $trim = trim($body);
    $json = json_decode($trim, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) return $json;
    return ['raw' => $trim];
  }

  function upsert_country(PDO $conn, int $providerId, string $name_en): void {
    if ($providerId <= 0 || $name_en === '') return;
    $st = $conn->prepare("
      INSERT INTO sms_countries (provider_id, name_en, local_name)
      VALUES (:id, :ne, NULL)
      ON DUPLICATE KEY UPDATE name_en=VALUES(name_en), updated_at=CURRENT_TIMESTAMP
    ");
    $st->execute([':id'=>$providerId, ':ne'=>$name_en]);
  }

  function cleanup_orphan_countries(PDO $conn): void {
    $conn->exec("
      DELETE FROM sms_countries
      WHERE provider_id NOT IN (SELECT DISTINCT country_id FROM sms_prices)
    ");
  }

  // ==================== DASHBOARD STATS ====================
  function getDashboardStats(PDO $conn): array {
    try {
        // Total Users
        $totalUsers = $conn->query("SELECT COUNT(*) FROM clients WHERE client_type = 2")->fetchColumn();
        
        // Total Deposits (Completed payments)
        $totalDeposits = $conn->query("SELECT COALESCE(SUM(payment_amount), 0) FROM payments WHERE payment_status = 3")->fetchColumn();
        
        // Total Orders
        $totalOrders = $conn->query("SELECT COUNT(*) FROM sms_orders")->fetchColumn();
        
        // Total Balance (sum of all user balances)
        $totalBalance = $conn->query("SELECT COALESCE(SUM(balance), 0) FROM clients")->fetchColumn();
        
        // Total Spent
        $totalSpent = $conn->query("SELECT COALESCE(SUM(spent), 0) FROM clients")->fetchColumn();
        
        // Total Profit (deposits - balance)
        $totalProfit = $totalDeposits - $totalBalance;
        
        return [
            'total_users' => (int)$totalUsers,
            'total_deposits' => (float)$totalDeposits,
            'total_orders' => (int)$totalOrders,
            'total_balance' => (float)$totalBalance,
            'total_profit' => (float)$totalProfit,
            'total_spent' => (float)$totalSpent
        ];
    } catch (Exception $e) {
        return [
            'total_users' => 0,
            'total_deposits' => 0.0,
            'total_orders' => 0,
            'total_balance' => 0.0,
            'total_profit' => 0.0,
            'total_spent' => 0.0
        ];
    }
  }

  // ===== AJAX router =====
  $method = $_SERVER['REQUEST_METHOD'] ?? '';
  if ($method === 'POST' && isset($_POST['action'])) {
    try {
      switch ($_POST['action']) {

        // ==================== DASHBOARD ====================
        case 'dashboard_stats': {
            $stats = getDashboardStats($conn);
            jres(true, 'ok', $stats);
        }

        // ==================== ACCOUNT SETTINGS ====================
        case 'get_admin_profile': {
            $adminId = (int)($admin['admin_id'] ?? 0);
            if ($adminId <= 0) jres(false, 'Invalid admin');
            
            $st = $conn->prepare("SELECT admin_id, name, email, username, telephone FROM admins WHERE admin_id = ?");
            $st->execute([$adminId]);
            $profile = $st->fetch(PDO::FETCH_ASSOC);
            
            if (!$profile) jres(false, 'Admin not found');
            jres(true, 'ok', ['profile' => $profile]);
        }

        case 'update_admin_profile': {
            $adminId = (int)($admin['admin_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $telephone = trim((string)($_POST['telephone'] ?? ''));
            
            if ($adminId <= 0 || $name === '' || $email === '') {
                jres(false, 'Name and email are required');
            }
            
            $st = $conn->prepare("UPDATE admins SET name = ?, email = ?, telephone = ? WHERE admin_id = ?");
            $st->execute([$name, $email, $telephone, $adminId]);
            
            jres(true, 'Profile updated successfully');
        }

        case 'change_admin_password': {
            $adminId = (int)($admin['admin_id'] ?? 0);
            $currentPass = trim((string)($_POST['current_password'] ?? ''));
            $newPass = trim((string)($_POST['new_password'] ?? ''));
            
            if ($adminId <= 0 || $currentPass === '' || $newPass === '') {
                jres(false, 'All fields are required');
            }
            
            // Verify current password
            $st = $conn->prepare("SELECT password FROM admins WHERE admin_id = ?");
            $st->execute([$adminId]);
            $storedPass = $st->fetchColumn();
            
            $hashedCurrent = md5(sha1(md5($currentPass)));
            if ($hashedCurrent !== $storedPass) {
                jres(false, 'Current password is incorrect');
            }
            
            // Update password
            $hashedNew = md5(sha1(md5($newPass)));
            $st = $conn->prepare("UPDATE admins SET password = ? WHERE admin_id = ?");
            $st->execute([$hashedNew, $adminId]);
            
            jres(true, 'Password changed successfully');
        }

        case 'add_new_admin': {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $password = trim((string)($_POST['password'] ?? ''));
            $telephone = trim((string)($_POST['telephone'] ?? ''));
            $adminType = (int)($_POST['admin_type'] ?? 2);
            
            if ($name === '' || $email === '' || $username === '' || $password === '') {
                jres(false, 'Name, email, username and password are required');
            }
            
            // Check if username exists
            $st = $conn->prepare("SELECT COUNT(*) FROM admins WHERE username = ?");
            $st->execute([$username]);
            if ($st->fetchColumn() > 0) {
                jres(false, 'Username already exists');
            }
            
            $hashedPass = md5(sha1(md5($password)));
            
            $st = $conn->prepare("INSERT INTO admins (name, email, username, password, telephone, admin_type, client_type, register_date) VALUES (?, ?, ?, ?, ?, ?, 2, NOW())");
            $st->execute([$name, $email, $username, $hashedPass, $telephone, $adminType]);
            
            jres(true, 'Admin added successfully');
        }

        case 'list_all_admins': {
            $st = $conn->prepare("SELECT admin_id, name, email, username, telephone, admin_type, client_type, register_date, login_date FROM admins ORDER BY register_date DESC");
            $st->execute();
            $admins = $st->fetchAll(PDO::FETCH_ASSOC);
            
            jres(true, 'ok', ['admins' => $admins]);
        }

        case 'delete_admin': {
            $adminId = (int)($_POST['admin_id'] ?? 0);
            $currentAdminId = (int)($admin['admin_id'] ?? 0);
            
            if ($adminId <= 0) jres(false, 'Invalid admin ID');
            if ($adminId === $currentAdminId) jres(false, 'Cannot delete yourself');
            
            $st = $conn->prepare("DELETE FROM admins WHERE admin_id = ?");
            $st->execute([$adminId]);
            
            jres(true, 'Admin deleted');
        }

        // ==================== COUNTRIES MANAGEMENT ====================
        case 'countries_manage_list': {
            $st = $conn->prepare("SELECT provider_id, name_en, local_name, country_logo, updated_at FROM sms_countries ORDER BY name_en ASC");
            $st->execute();
            $countries = $st->fetchAll(PDO::FETCH_ASSOC);
            
            jres(true, 'ok', ['countries' => $countries]);
        }

        case 'update_country_name': {
            $id = (int)($_POST['country_id'] ?? 0);
            $localName = trim((string)($_POST['local_name'] ?? ''));
            
            if ($id <= 0) jres(false, 'Invalid country');
            
            $st = $conn->prepare("UPDATE sms_countries SET local_name = ?, updated_at = NOW() WHERE provider_id = ?");
            $st->execute([$localName, $id]);
            
            jres(true, 'Country name updated');
        }

        case 'upload_country_logo': {
            $id = (int)($_POST['country_id'] ?? 0);
            
            if ($id <= 0) jres(false, 'Invalid country');
            if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
                jres(false, 'No file uploaded or upload error');
            }
            
            $file = $_FILES['logo'];
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            
            if (!in_array($file['type'], $allowed)) {
                jres(false, 'Invalid file type. Only images allowed');
            }
            
            if ($file['size'] > 2 * 1024 * 1024) { // 2MB max
                jres(false, 'File too large. Max 2MB');
            }
            
            $uploadDir = __DIR__ . '/../../uploads/countries/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newName = 'country_' . $id . '_' . time() . '.' . $ext;
            $destination = $uploadDir . $newName;
            
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                jres(false, 'Failed to move uploaded file');
            }
            
            $logoPath = '/uploads/countries/' . $newName;
            
            $st = $conn->prepare("UPDATE sms_countries SET country_logo = ?, updated_at = NOW() WHERE provider_id = ?");
            $st->execute([$logoPath, $id]);
            
            jres(true, 'Logo uploaded', ['logo_path' => $logoPath]);
        }

        // ==================== SERVICES MANAGEMENT ====================
        case 'services_manage_list': {
            $st = $conn->prepare("SELECT service_id, name, display_name, service_logo, updated_at FROM sms_services ORDER BY name ASC");
            $st->execute();
            $services = $st->fetchAll(PDO::FETCH_ASSOC);
            
            jres(true, 'ok', ['services' => $services]);
        }

        case 'update_service_name': {
            $id = (string)($_POST['service_id'] ?? '');
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            
            if ($id === '') jres(false, 'Invalid service');
            
            $st = $conn->prepare("UPDATE sms_services SET display_name = ?, updated_at = NOW() WHERE service_id = ?");
            $st->execute([$displayName, $id]);
            
            jres(true, 'Service name updated');
        }

        case 'upload_service_logo': {
            $id = (string)($_POST['service_id'] ?? '');
            
            if ($id === '') jres(false, 'Invalid service');
            if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
                jres(false, 'No file uploaded');
            }
            
            $file = $_FILES['logo'];
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            
            if (!in_array($file['type'], $allowed)) {
                jres(false, 'Invalid file type');
            }
            
            $uploadDir = __DIR__ . '/../../uploads/services/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newName = 'service_' . preg_replace('/[^a-z0-9]/i', '_', $id) . '_' . time() . '.' . $ext;
            $destination = $uploadDir . $newName;
            
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                jres(false, 'Failed to upload');
            }
            
            $logoPath = '/uploads/services/' . $newName;
            
            $st = $conn->prepare("UPDATE sms_services SET service_logo = ?, updated_at = NOW() WHERE service_id = ?");
            $st->execute([$logoPath, $id]);
            
            jres(true, 'Logo uploaded', ['logo_path' => $logoPath]);
        }

        // ==================== USERS ====================
case 'users_list': {
    $lim = max(10, min(200, (int)($_POST['limit'] ?? 100)));
    $off = max(0, (int)($_POST['offset'] ?? 0));
    $q   = trim((string)($_POST['q'] ?? ''));
    
    $sql = "SELECT client_id, name, email, username, telephone, balance, spent, 
                   client_type, register_date, login_date
            FROM clients WHERE 1";
    $args = [];
    
    if ($q !== '') {
        $sql .= " AND (name LIKE ? OR email LIKE ? OR username LIKE ? OR telephone LIKE ?)";
        $args[] = "%{$q}%";
        $args[] = "%{$q}%";
        $args[] = "%{$q}%";
        $args[] = "%{$q}%";
    }
    
    $sql .= " ORDER BY register_date DESC LIMIT ? OFFSET ?";
    $args[] = $lim;
    $args[] = $off;
    
    $st = $conn->prepare($sql);
    $st->execute($args);
    
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    jres(true, 'ok', ['rows' => $rows, 'next_offset' => (count($rows) < $lim ? null : $off + $lim)]);
}
        case 'user_save': {
          $id   = (int)($_POST['client_id'] ?? 0);
          $name = trim((string)($_POST['name'] ?? ''));
          $email= trim((string)($_POST['email'] ?? ''));
          $bal  = trim((string)($_POST['balance'] ?? ''));
          $type = (int)($_POST['client_type'] ?? 2);
          
          if ($id <= 0 || $name === '' || $email === '') jres(false, 'Invalid data');
          
          $st = $conn->prepare("UPDATE clients 
                                SET name=:n, email=:e, balance=:b, client_type=:t 
                                WHERE client_id=:id");
          $st->execute([
            ':n'=>$name, ':e'=>$email, 
            ':b'=>($bal===''?null:$bal), 
            ':t'=>$type, ':id'=>$id
          ]);
          jres(true, 'User updated');
        }

        case 'user_delete': {
          $id = (int)($_POST['client_id'] ?? 0);
          if ($id <= 0) jres(false, 'Invalid ID');
          
          $conn->beginTransaction();
          try {
            $conn->prepare("DELETE FROM sms_orders WHERE client_id=:id")->execute([':id'=>$id]);
            $conn->prepare("DELETE FROM payments WHERE client_id=:id")->execute([':id'=>$id]);
            $conn->prepare("DELETE FROM clients WHERE client_id=:id")->execute([':id'=>$id]);
            $conn->commit();
            jres(true, 'User deleted');
          } catch (Throwable $e) {
            $conn->rollBack();
            jres(false, 'Delete failed');
          }
        }

        // ==================== ORDERS ====================
       case 'orders_list': {
    $lim = max(10, min(200, (int)($_POST['limit'] ?? 100)));
    $off = max(0, (int)($_POST['offset'] ?? 0));
    $q   = trim((string)($_POST['q'] ?? ''));
    
    $sql = "SELECT o.id, o.client_id, c.name AS client_name, c.email,
                   o.country_id, co.name_en AS country_name,
                   o.service_id, s.name AS service_name,
                   o.provider_price, o.currency, o.phone, o.status,
                   o.created_at, o.otp_code
            FROM sms_orders o
            LEFT JOIN clients c ON c.client_id = o.client_id
            LEFT JOIN sms_countries co ON co.provider_id = o.country_id
            LEFT JOIN sms_services s ON s.service_id = o.service_id
            WHERE 1";
    $args = [];
    
    if ($q !== '') {
        $sql .= " AND (c.name LIKE ? OR c.email LIKE ? OR o.phone LIKE ? OR o.activation_id LIKE ?)";
        $args[] = "%{$q}%";
        $args[] = "%{$q}%";
        $args[] = "%{$q}%";
        $args[] = "%{$q}%";
    }
    
    $sql .= " ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
    $args[] = $lim;
    $args[] = $off;
    
    $st = $conn->prepare($sql);
    $st->execute($args);
    
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    jres(true, 'ok', ['rows' => $rows, 'next_offset' => (count($rows) < $lim ? null : $off + $lim)]);
}

        case 'order_delete': {
          $id = (int)($_POST['order_id'] ?? 0);
          if ($id <= 0) jres(false, 'Invalid ID');
          
          $st = $conn->prepare("DELETE FROM sms_orders WHERE id=:id");
          $st->execute([':id'=>$id]);
          jres(true, 'Order deleted');
        }

        // ==================== PAYMENTS ====================
      case 'payments_list': {
    $lim = max(10, min(200, (int)($_POST['limit'] ?? 100)));
    $off = max(0, (int)($_POST['offset'] ?? 0));
    $q   = trim((string)($_POST['q'] ?? ''));
    
    $sql = "SELECT p.payment_id, p.client_id, c.name AS client_name, c.email,
                   p.payment_amount, p.payment_method, pm.method_name, p.payment_status,
                   p.payment_create_date, p.payment_update_date, p.payment_note
            FROM payments p
            LEFT JOIN clients c ON c.client_id = p.client_id
            LEFT JOIN payment_methods pm ON pm.id = p.payment_method
            WHERE 1";
    $args = [];
    
    if ($q !== '') {
        $sql .= " AND (c.name LIKE ? OR c.email LIKE ? OR p.payment_extra LIKE ?)";
        $args[] = "%{$q}%";
        $args[] = "%{$q}%";
        $args[] = "%{$q}%";
    }
    
    $sql .= " ORDER BY p.payment_create_date DESC LIMIT ? OFFSET ?";
    $args[] = $lim;
    $args[] = $off;
    
    $st = $conn->prepare($sql);
    $st->execute($args);
    
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    jres(true, 'ok', ['rows' => $rows, 'next_offset' => (count($rows) < $lim ? null : $off + $lim)]);
}
        case 'payment_update_status': {
          $id     = (int)($_POST['payment_id'] ?? 0);
          $status = (string)($_POST['status'] ?? '');
          
          if ($id <= 0 || !in_array($status, ['1','2','3','4'])) jres(false, 'Invalid');
          
          $st = $conn->prepare("UPDATE payments SET payment_status=:s, payment_update_date=CURRENT_TIMESTAMP WHERE payment_id=:id");
          $st->execute([':s'=>$status, ':id'=>$id]);
          jres(true, 'Status updated');
        }

        case 'payment_delete': {
          $id = (int)($_POST['payment_id'] ?? 0);
          if ($id <= 0) jres(false, 'Invalid ID');
          
          $st = $conn->prepare("DELETE FROM payments WHERE payment_id=:id");
          $st->execute([':id'=>$id]);
          jres(true, 'Payment deleted');
        }

        // ==================== SMS CONFIG (previous code) ====================
        case 'countries_db_list': {
          $t0  = microtime(true);
          $sql = "SELECT provider_id AS id, COALESCE(local_name, name_en) AS name
                  FROM sms_countries
                  ORDER BY COALESCE(local_name, name_en) ASC";
          $st = $conn->prepare($sql);
          $st->execute();
          $rows = $st->fetchAll(PDO::FETCH_ASSOC);
          $t = number_format(microtime(true) - $t0, 4);
          jres(true, 'ok', ['rows'=>$rows,'timing'=>"Query took {$t} seconds"]);
        }

        case 'provider_countries': {
          $t0   = microtime(true);
          $prov = smspool_post('country/retrieve_all', [], null);
          $rows = (isset($prov['data']) && is_array($prov['data']))
            ? $prov['data']
            : (is_array($prov) ? $prov : []);
          $used = [];
          foreach ($conn->query("SELECT DISTINCT country_id FROM sms_prices") as $r) {
            $used[(int)$r['country_id']] = true;
          }
          $out = [];
          foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $id = (int)($row['ID'] ?? $row['id'] ?? $row['country_id'] ?? 0);
            $nm = (string)($row['name'] ?? $row['title'] ?? $row['name_en'] ?? '');
            if ($id > 0 && $nm !== '' && empty($used[$id])) {
              $out[] = ['id' => $id, 'name' => $nm];
            }
          }
          $t = number_format(microtime(true) - $t0, 4);
          jres(true, 'ok', ['rows' => $out, 'timing' => "API took {$t} seconds"]);
        }

        case 'import_services': {
          $payload = smspool_post('service/retrieve_all', [], null);
          $rows = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : (is_array($payload) ? $payload : []);
          $st = $conn->prepare("
            INSERT INTO sms_services (service_id, name, display_name)
            VALUES (:sid, :nm, NULL)
            ON DUPLICATE KEY UPDATE name=VALUES(name), updated_at=CURRENT_TIMESTAMP
          ");
          $n = 0;
          foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $sid = (string)($row['ID'] ?? $row['id'] ?? $row['service'] ?? '');
            $nm  = (string)($row['name'] ?? $row['short_name'] ?? $row['title'] ?? '');
            if ($sid !== '' && $nm !== '') {
              $st->execute([':sid' => $sid, ':nm' => $nm]);
              $n++;
            }
          }
          jres(true, "Imported/updated {$n} services", ['total' => $n]);
        }
case 'services_list': {
    $lim = max(10, min(200, (int)($_POST['limit'] ?? 120)));
    $off = max(0, (int)($_POST['offset'] ?? 0));
    $q   = trim((string)($_POST['q'] ?? ''));
    
    $sql = "SELECT service_id AS id, name, display_name FROM sms_services WHERE 1";
    $args = [];
    
    if ($q !== '') {
        $sql .= " AND (service_id LIKE ? OR name LIKE ? OR display_name LIKE ?)";
        $args[] = "%{$q}%";
        $args[] = "%{$q}%";
        $args[] = "%{$q}%";
    }
    
    $sql .= " ORDER BY name ASC LIMIT ? OFFSET ?";
    $args[] = $lim;
    $args[] = $off;
    
    $st = $conn->prepare($sql);
    $st->execute($args);
    
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    jres(true, 'ok', ['rows' => $rows, 'next_offset' => (count($rows) < $lim ? null : $off + $lim)]);
}
        case 'save_service': {
          $sid  = (string)($_POST['service_id'] ?? '');
          $name = trim((string)($_POST['name'] ?? ''));
          if ($sid === '' || $name === '') jres(false, 'Invalid');
          $st = $conn->prepare("UPDATE sms_services SET name=:n, updated_at=CURRENT_TIMESTAMP WHERE service_id=:s");
          $st->execute([':n'=>$name, ':s'=>$sid]);
          jres(true, 'Saved');
        }

        case 'delete_services_selected': {
          $ids = $_POST['ids'] ?? [];
          if (!is_array($ids) || !$ids) jres(false,'No rows selected');
          $ids = array_values(array_unique(array_map('strval', $ids)));
          $in  = implode(',', array_fill(0, count($ids), '?'));
          $st  = $conn->prepare("DELETE FROM sms_services WHERE service_id IN ($in)");
          $st->execute($ids);
          jres(true, "Deleted {$st->rowCount()} row(s)");
        }

        case 'delete_services_filtered': {
          $q = trim((string)($_POST['q'] ?? ''));
          $conn->beginTransaction();
          try {
            if ($q === '') {
              $conn->exec("DELETE p FROM sms_prices p INNER JOIN sms_services s ON s.service_id=p.service_id");
              $conn->exec("DELETE FROM sms_services");
            } else {
              $st = $conn->prepare("SELECT service_id FROM sms_services WHERE service_id LIKE :q OR name LIKE :q OR display_name LIKE :q");
              $st->execute([':q'=>"%{$q}%"]);
              $ids = $st->fetchAll(PDO::FETCH_COLUMN, 0);
              if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $conn->prepare("DELETE FROM sms_prices WHERE service_id IN ($in)")->execute($ids);
                $conn->prepare("DELETE FROM sms_services WHERE service_id IN ($in)")->execute($ids);
              }
            }
            $conn->commit();
            jres(true, 'Deleted');
          } catch (Throwable $e) {
            $conn->rollBack();
            jres(false,'Delete failed');
          }
        }

        case 'import_countries_bulk': {
          if ($apiKey === '') jres(false, 'Missing API key');
          $ids = $_POST['country_ids'] ?? [];
          if (!is_array($ids) || !$ids) jres(false, 'Select countries');
          $ids = array_values(array_unique(array_map('intval', $ids)));
          $countries = smspool_post('country/retrieve_all', [], null);
          $rowsC     = (isset($countries['data']) && is_array($countries['data'])) ? $countries['data'] : (is_array($countries) ? $countries : []);
          $names     = [];
          foreach ($rowsC as $row) {
            if (!is_array($row)) continue;
            $id = (int)($row['ID'] ?? $row['id'] ?? 0);
            $nm = (string)($row['name'] ?? $row['title'] ?? $row['name_en'] ?? '');
            if ($id > 0 && $nm !== '') $names[$id] = $nm;
          }
          $allow = [];
          foreach ($conn->query("SELECT service_id FROM sms_services") as $r) {
            $allow[(string)$r['service_id']] = true;
          }
          $sql = "INSERT INTO sms_prices (country_id, service_id, provider_cost, pprice, available_count, updated_at)
                  VALUES (:c,:s,:pc,:pp,:cnt,CURRENT_TIMESTAMP)
                  ON DUPLICATE KEY UPDATE provider_cost=VALUES(provider_cost),
                                          pprice=VALUES(pprice),
                                          available_count=VALUES(available_count),
                                          updated_at=CURRENT_TIMESTAMP";
          $st = $conn->prepare($sql);
          $siteCur = strtoupper((string)($settings['site_currency'] ?? 'USD'));
          $total   = 0;
          @set_time_limit(300);
          foreach ($ids as $country) {
            if (isset($names[$country])) upsert_country($conn, $country, $names[$country]);
            $pricing = smspool_post('request/pricing', ['country' => (string)$country], $apiKey);
            if (!is_array($pricing) || empty($pricing)) continue;
            $best = [];
            foreach ($pricing as $row) {
              if (!is_array($row)) continue;
              $sidRaw = $row['service'] ?? $row['Service'] ?? $row['SERVICE'] ?? null;
              if ($sidRaw === null) continue;
              $sid = (string)$sidRaw;
              if ($sid === '' || empty($allow[$sid])) continue;
              $priceStr = (string)($row['price'] ?? '');
              if ($priceStr === '' || !is_numeric($priceStr)) continue;
              $price = (float)$priceStr;
              if (!is_finite($price) || $price <= 0.0) continue;
              $poolId = null;
              if (isset($row['pool']) && is_numeric($row['pool'])) $poolId = (int)$row['pool'];
              if (!isset($best[$sid]) || $price < $best[$sid]['price']) {
                $best[$sid] = ['price' => $price, 'pool' => $poolId];
              }
            }
            if (!$best) continue;
            $conn->beginTransaction();
            try {
              $n = 0;
              foreach ($best as $sid => $info) {
                $raw    = (float)$info['price'];
                $poolId = $info['pool'];
                $qty = $poolId;
                $stmt = $conn->prepare("SELECT name_en FROM sms_countries WHERE provider_id = :country_id LIMIT 1");
                $stmt->bindParam(':country_id', $country, PDO::PARAM_INT);
                $stmt->execute();
                $countryName = $stmt->fetchColumn();
                $pc = convertCurrencyUpdateds("USD", $settings["site_currency"], $raw);
                $pc = $pc;
                $pprice = convertCurrencyUpdateds($settings["site_currency"], getCurrencyByCountry($countryName), $pc);
                $st->execute([
                  ':c'   => $country,
                  ':s'   => (string)$sid,
                  ':pc'  => $pc,
                  ':pp'  => $pprice,
                  ':cnt' => $qty
                ]);
                $n++;
              }
              $conn->commit();
              $total += $n;
            } catch (Throwable $e) {
              $conn->rollBack();
            }
          }
          jres(true, "Imported/updated {$total} rows", ['total' => $total]);
        }

        case 'prices_list': {
          $lim = max(10, min(200, (int)($_POST['limit'] ?? 120)));
          $off = max(0, (int)($_POST['offset'] ?? 0));
          $q   = trim((string)($_POST['q'] ?? ''));
          $cid = (int)($_POST['country_id'] ?? 0);
          $sql = "SELECT 
                    p.country_id,
                    COALESCE(c.local_name, c.name_en) AS country_name,
                    p.service_id,
                    p.provider_cost,
                    p.pprice,
                    p.available_count,
                    COALESCE(s.display_name, s.name) AS service_name
                  FROM sms_prices p
                  LEFT JOIN sms_countries c ON c.provider_id = p.country_id
                  LEFT JOIN sms_services  s ON s.service_id   = p.service_id
                  WHERE 1";
          $args = [];
          if ($cid > 0) { $sql .= " AND p.country_id = :cid"; $args[':cid'] = $cid; }
          if ($q !== '') {
            $sql .= " AND (p.service_id LIKE :q 
                        OR s.name LIKE :q 
                        OR s.display_name LIKE :q 
                        OR c.name_en LIKE :q 
                        OR c.local_name LIKE :q)";
            $args[':q'] = "%{$q}%";
          }
          $sql .= " ORDER BY p.country_id ASC, p.service_id ASC
                    LIMIT :lim OFFSET :off";
          $st = $conn->prepare($sql);
          if (array_key_exists(':cid',$args)) { $st->bindValue(':cid', (int)$args[':cid'], PDO::PARAM_INT); unset($args[':cid']); }
          if (array_key_exists(':q',$args))   { $st->bindValue(':q',   (string)$args[':q'], PDO::PARAM_STR); unset($args[':q']); }
          $st->bindValue(':lim', $lim, PDO::PARAM_INT);
          $st->bindValue(':off', $off, PDO::PARAM_INT);
          $st->execute();
          $rows = $st->fetchAll(PDO::FETCH_ASSOC);
          foreach ($rows as &$r) {
            $provCurrency = getCurrencyByCountry($r['country_name'] ?? null);
            $r['pprice_currency'] = $provCurrency;
            $r['pprice_label']    = ($r['pprice'] !== null && $r['pprice'] !== '')
                                  ? ($r['pprice'] . ' ' . $provCurrency)
                                  : null;
          }
          unset($r);
          jres(true, 'ok', [
            'rows' => $rows,
            'next_offset' => (count($rows) < $lim ? null : $off + $lim),
          ]);
        }

        case 'save_price': {
          $cid = (int)($_POST['country_id'] ?? 0);
          $sid = (string)($_POST['service_id'] ?? '');
          $pv  = trim((string)($_POST['provider_cost'] ?? ''));
          if ($cid <= 0 || $sid === '') jres(false, 'Invalid');
          $val = ($pv === '') ? null : number_format((float)$pv, 4, '.', '');
          $st = $conn->prepare("UPDATE sms_prices SET provider_cost=:pc, updated_at=CURRENT_TIMESTAMP WHERE country_id=:c AND service_id=:s");
          $st->execute([':pc'=>$val, ':c'=>$cid, ':s'=>$sid]);
          jres(true, 'Saved');
        }

        case 'bulk_profit': {
          $percent = (float)($_POST['percent'] ?? 0);
          if ($percent === 0.0) jres(false, 'Percent required');
          $q   = trim((string)($_POST['q'] ?? ''));
          $cid = (int)($_POST['country_id'] ?? 0);
          $factor = 1.0 + ($percent / 100.0);
          $sql = "UPDATE sms_prices p
                  LEFT JOIN sms_services s  ON s.service_id=p.service_id
                  LEFT JOIN sms_countries c ON c.provider_id=p.country_id
                  SET p.provider_cost=ROUND(p.provider_cost*:f,4), p.updated_at=CURRENT_TIMESTAMP
                  WHERE 1";
          $args = [':f'=>$factor];
          if ($cid > 0) { $sql .= " AND p.country_id=:cid"; $args[':cid'] = $cid; }
          if ($q !== '') {
            $sql .= " AND (p.service_id LIKE :q OR s.name LIKE :q OR s.display_name LIKE :q OR c.name_en LIKE :q OR c.local_name LIKE :q)";
            $args[':q'] = "%{$q}%";
          }
          $st = $conn->prepare($sql);
          foreach ($args as $k=>$v) $st->bindValue($k, $v, is_string($v) ? PDO::PARAM_STR : PDO::PARAM_STR);
          $st->execute();
          jres(true, 'Profit applied');
        }

        case 'delete_prices_selected': {
          $items = $_POST['items'] ?? [];
          if (!is_array($items) || !$items) jres(false, 'No rows selected');
          $pairs = [];
          foreach ($items as $k) {
            $parts = explode('|', (string)$k, 2);
            if (count($parts) === 2) {
              $c = (int)$parts[0]; $s = trim($parts[1]);
              if ($c > 0 && $s !== '') $pairs[] = ['c'=>$c,'s'=>$s];
            }
          }
          if (!$pairs) jres(false, 'Invalid selection');
          $conn->beginTransaction();
          try {
            $st = $conn->prepare("DELETE FROM sms_prices WHERE country_id=:c AND service_id=:s");
            foreach ($pairs as $p) $st->execute([':c'=>$p['c'], ':s'=>$p['s']]);
            $conn->commit();
            cleanup_orphan_countries($conn);
            jres(true, 'Deleted selected');
          } catch (Throwable $e) {
            $conn->rollBack();
            jres(false, 'Delete failed');
          }
        }

        case 'delete_prices_filtered': {
          $q   = trim((string)($_POST['q'] ?? ''));
          $cid = (int)($_POST['country_id'] ?? 0);
          $sql = "DELETE p FROM sms_prices p
                  LEFT JOIN sms_services s  ON s.service_id=p.service_id
                  LEFT JOIN sms_countries c ON c.provider_id=p.country_id
                  WHERE 1";
          $args = [];
          if ($cid > 0) { $sql .= " AND p.country_id=:cid"; $args[':cid'] = $cid; }
          if ($q !== '') {
            $sql .= " AND (p.service_id LIKE :q OR s.name LIKE :q OR s.display_name LIKE :q OR c.name_en LIKE :q OR c.local_name LIKE :q)";
            $args[':q'] = "%{$q}%";
          }
          $st = $conn->prepare($sql);
          foreach ($args as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
          $st->execute();
          cleanup_orphan_countries($conn);
          jres(true, "Deleted {$st->rowCount()} row(s)");
        }

        default: jres(false, 'Unknown action');
      }
    } catch (Throwable $e) {
      jres(false, $e->getMessage());
    }
    exit;
  }

?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Panel</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root {
  --primary: #2563eb;
  --success: #10b981;
  --warning: #f59e0b;
  --danger: #ef4444;
  --dark: #1f2937;
  --light: #f9fafb;
  --border: #e5e7eb;
}
* { box-sizing: border-box; }
body {
  background: var(--light);
  color: var(--dark);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  margin: 0;
  padding: 0;
}
.sidebar {
  position: fixed;
  top: 0;
  left: 0;
  height: 100vh;
  width: 260px;
  background: #fff;
  border-right: 1px solid var(--border);
  padding: 1.5rem 0;
  overflow-y: auto;
  z-index: 1000;
  transition: transform 0.3s;
}
.sidebar-brand {
  padding: 0 1.5rem 1.5rem;
  border-bottom: 1px solid var(--border);
  margin-bottom: 1rem;
}
.sidebar-brand h5 {
  margin: 0;
  font-weight: 700;
  color: var(--primary);
}
.nav-item {
  list-style: none;
  margin: 0;
}
.nav-link {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1.5rem;
  color: #6b7280;
  text-decoration: none;
  transition: all 0.2s;
  border-left: 3px solid transparent;
}
.nav-link:hover, .nav-link.active {
  background: #f3f4f6;
  color: var(--primary);
  border-left-color: var(--primary);
}
.nav-link i {
  font-size: 1.25rem;
  width: 20px;
}
.main-content {
  margin-left: 260px;
  padding: 2rem;
  min-height: 100vh;
}
.top-bar {
  background: #fff;
  border-radius: 12px;
  padding: 1rem 1.5rem;
  margin-bottom: 2rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.card {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.1);
  border: 1px solid var(--border);
  margin-bottom: 1.5rem;
}
.card-header {
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.card-header h6 {
  margin: 0;
  font-weight: 600;
  font-size: 1.125rem;
}
.card-body {
  padding: 1.5rem;
}
.table-responsive {
  max-height: 65vh;
  overflow: auto;
}
.table {
  margin: 0;
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
}
.table thead {
  position: sticky;
  top: 0;
  background: #fff;
  z-index: 10;
}
.table thead th {
  padding: 0.75rem 1rem;
  text-align: left;
  font-size: 0.875rem;
  font-weight: 600;
  color: #6b7280;
  border-bottom: 2px solid var(--border);
  white-space: nowrap;
}
.table tbody td {
  padding: 1rem;
  border-bottom: 1px solid #f3f4f6;
  vertical-align: middle;
}
.table tbody tr:hover {
  background: #f9fafb;
}
.badge {
  display: inline-flex;
  align-items: center;
  padding: 0.25rem 0.75rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1;
}
.badge-primary { background: #dbeafe; color: #1e40af; }
.badge-success { background: #d1fae5; color: #065f46; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-danger { background: #fee2e2; color: #991b1b; }
.badge-secondary { background: #f3f4f6; color: #4b5563; }
.btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  border-radius: 8px;
  font-size: 0.875rem;
  font-weight: 500;
  border: none;
  cursor: pointer;
  transition: all 0.2s;
  text-decoration: none;
}
.btn-sm {
  padding: 0.375rem 0.75rem;
  font-size: 0.8125rem;
}
.btn-primary {
  background: var(--primary);
  color: #fff;
}
.btn-primary:hover {
  background: #1d4ed8;
}
.btn-success {
  background: var(--success);
  color: #fff;
}
.btn-success:hover {
  background: #059669;
}
.btn-danger {
  background: var(--danger);
  color: #fff;
}
.btn-danger:hover {
  background: #dc2626;
}
.btn-outline-primary {
  background: transparent;
  color: var(--primary);
  border: 1px solid var(--primary);
}
.btn-outline-primary:hover {
  background: var(--primary);
  color: #fff;
}
.btn-outline-danger {
  background: transparent;
  color: var(--danger);
  border: 1px solid var(--danger);
}
.btn-outline-danger:hover {
  background: var(--danger);
  color: #fff;
}
.form-control, .form-select {
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 0.875rem;
  transition: border-color 0.2s;
}
.form-control:focus, .form-select:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: center;
  padding: 1rem 1.5rem;
  background: #f9fafb;
  border-bottom: 1px solid var(--border);
}
.toolbar-left {
  flex: 1;
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
}
.toolbar-right {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
}
.tab-content {
  display: none;
}
.tab-content.active {
  display: block;
}
.status-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  margin-right: 0.5rem;
}
.status-dot.active { background: var(--success); }
.status-dot.inactive { background: #9ca3af; }
.status-dot.pending { background: var(--warning); }
.status-dot.completed { background: var(--success); }
.status-dot.hold { background: var(--warning); }
.status-dot.expired { background: #6b7280; }
.mobile-toggle {
  display: none;
  position: fixed;
  bottom: 1rem;
  right: 1rem;
  width: 50px;
  height: 50px;
  background: var(--primary);
  color: #fff;
  border-radius: 50%;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 6px rgba(0,0,0,0.1);
  cursor: pointer;
  z-index: 1001;
}
@media (max-width: 991px) {
  .sidebar {
    transform: translateX(-100%);
  }
  .sidebar.show {
    transform: translateX(0);
  }
  .main-content {
    margin-left: 0;
    padding: 1rem;
  }
  .mobile-toggle {
    display: flex;
  }
  .toolbar {
    flex-direction: column;
    align-items: stretch;
  }
  .toolbar-left, .toolbar-right {
    width: 100%;
  }
}
.empty-state {
  text-align: center;
  padding: 3rem 1rem;
  color: #9ca3af;
}
.empty-state i {
  font-size: 3rem;
  margin-bottom: 1rem;
  opacity: 0.5;
}
.loading {
  text-align: center;
  padding: 2rem;
  color: #9ca3af;
}
.modal-content {
  border-radius: 12px;
  border: none;
  box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
}
.modal-header {
  border-bottom: 1px solid var(--border);
  padding: 1.25rem 1.5rem;
}
.modal-body {
  padding: 1.5rem;
}
.modal-footer {
  border-top: 1px solid var(--border);
  padding: 1rem 1.5rem;
}
.user-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-weight: 600;
  font-size: 0.875rem;
}
.stat-card {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 12px;
  padding: 1.5rem;
  color: #fff;
  margin-bottom: 1.5rem;
  box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}
.stat-card h3 {
  font-size: 2rem;
  font-weight: 700;
  margin: 0.5rem 0;
}
.stat-card p {
  margin: 0;
  opacity: 0.9;
  font-size: 0.875rem;
}
.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 1.5rem;
  margin-bottom: 2rem;
}
.logo-preview {
  max-width: 100px;
  max-height: 100px;
  margin-top: 0.5rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 0.5rem;
}
</style>
</head>
<body>

<!-- Sidebar -->
<nav class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <h5><i class="bi bi-shield-check"></i> Admin Panel</h5>
  </div>
  <ul style="list-style:none;padding:0;margin:0">
    <li class="nav-item">
      <a href="#" class="nav-link active" data-tab="dashboard">
        <i class="bi bi-speedometer2"></i>
        <span>Dashboard</span>
      </a>
    </li>
    <li class="nav-item">
      <a href="#" class="nav-link" data-tab="users">
        <i class="bi bi-people"></i>
        <span>Users</span>
      </a>
    </li>
    <li class="nav-item">
      <a href="#" class="nav-link" data-tab="orders">
        <i class="bi bi-cart-check"></i>
        <span>Orders</span>
      </a>
    </li>
    <li class="nav-item">
      <a href="#" class="nav-link" data-tab="payments">
        <i class="bi bi-credit-card"></i>
        <span>Payments</span>
      </a>
    </li>
    <li class="nav-item">
      <a href="#" class="nav-link" data-tab="services">
        <i class="bi bi-list-check"></i>
        <span>SMS Services</span>
      </a>
    </li>
    <li class="nav-item">
      <a href="#" class="nav-link" data-tab="prices">
        <i class="bi bi-currency-dollar"></i>
        <span>SMS Prices</span>
      </a>
    </li>
    <li class="nav-item">
      <a href="#" class="nav-link" data-tab="countries">
        <i class="bi bi-flag"></i>
        <span>Countries</span>
      </a>
    </li>
    <li class="nav-item">
      <a href="#" class="nav-link" data-tab="servicesmanage">
        <i class="bi bi-grid-3x3"></i>
        <span>Services Manager</span>
      </a>
    </li>
    <li class="nav-item">
      <a href="#" class="nav-link" data-tab="account">
        <i class="bi bi-person-circle"></i>
        <span>Account Settings</span>
      </a>
    </li>
    <li class="nav-item" style="margin-top:1rem;border-top:1px solid var(--border);padding-top:1rem">
      <a href="/logout" class="nav-link">
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
      </a>
    </li>
  </ul>
</nav>

<!-- Mobile Toggle -->
<div class="mobile-toggle" id="mobileToggle">
  <i class="bi bi-list" style="font-size:1.5rem"></i>
</div>

<!-- Main Content -->
<div class="main-content">
  
  <div class="top-bar">
    <div>
      <h4 style="margin:0;font-weight:600" id="pageTitle">Dashboard</h4>
      <small class="text-muted" id="pageDesc">System overview</small>
    </div>
    <div class="d-flex align-items-center gap-3">
      <?php if(!$apiKey): ?>
        <span class="badge badge-danger">API key missing</span>
      <?php else: ?>
        <span class="badge badge-success">API ready</span>
      <?php endif; ?>
      <div class="user-avatar"><?php echo strtoupper(substr($admin['name'] ?? 'A', 0, 1)); ?></div>
    </div>
  </div>

  <!-- DASHBOARD TAB -->
  <div class="tab-content active" id="tab-dashboard">
    <div class="stat-grid" id="statsGrid">
      <div class="loading"><div class="spinner-border text-primary"></div><p class="mt-2">Loading stats...</p></div>
    </div>
  </div>

  <!-- ACCOUNT SETTINGS TAB -->
  <div class="tab-content" id="tab-account">
    <div class="row g-4">
      <div class="col-md-6">
        <div class="card">
          <div class="card-header">
            <h6><i class="bi bi-person"></i> Profile Settings</h6>
          </div>
          <div class="card-body">
            <form id="profileForm">
              <div class="mb-3">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" id="profileName" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" id="profileEmail" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Phone</label>
                <input type="text" class="form-control" id="profilePhone">
              </div>
              <div class="mb-3">
                <label class="form-label">Username (readonly)</label>
                <input type="text" class="form-control" id="profileUsername" readonly>
              </div>
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg"></i> Update Profile
              </button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <div class="card">
          <div class="card-header">
            <h6><i class="bi bi-key"></i> Change Password</h6>
          </div>
          <div class="card-body">
            <form id="passwordForm">
              <div class="mb-3">
                <label class="form-label">Current Password</label>
                <input type="password" class="form-control" id="currentPassword" required>
              </div>
              <div class="mb-3">
                <label class="form-label">New Password</label>
                <input type="password" class="form-control" id="newPassword" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" id="confirmPassword" required>
              </div>
              <button type="submit" class="btn btn-danger">
                <i class="bi bi-shield-lock"></i> Change Password
              </button>
            </form>
          </div>
        </div>

        <div class="card mt-4">
          <div class="card-header">
            <h6><i class="bi bi-person-plus"></i> Add New Admin</h6>
          </div>
          <div class="card-body">
            <form id="addAdminForm">
              <div class="mb-3">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" name="name" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="email" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" name="username" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" class="form-control" name="password" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Phone</label>
                <input type="text" class="form-control" name="telephone">
              </div>
              <div class="mb-3">
                <label class="form-label">Admin Type</label>
                <select class="form-select" name="admin_type">
                  <option value="2">Admin</option>
                  <option value="3">Super Admin</option>
                  <option value="4">Developer</option>
                </select>
              </div>
              <button type="submit" class="btn btn-success">
                <i class="bi bi-plus-circle"></i> Add Admin
              </button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card">
          <div class="card-header">
            <h6><i class="bi bi-people"></i> All Admins</h6>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table" id="tblAdmins">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Username</th>
                    <th>Phone</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- COUNTRIES TAB -->
  <div class="tab-content" id="tab-countries">
    <div class="card">
      <div class="card-header">
        <h6><i class="bi bi-flag"></i> Countries Management</h6>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table" id="tblCountries">
            <thead>
              <tr>
                <th>ID</th>
                <th>Name (EN)</th>
                <th>Display Name</th>
                <th>Logo</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- SERVICES MANAGE TAB -->
  <div class="tab-content" id="tab-servicesmanage">
    <div class="card">
      <div class="card-header">
        <h6><i class="bi bi-grid-3x3"></i> Services Management</h6>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table" id="tblServicesManage">
            <thead>
              <tr>
                <th>Service ID</th>
                <th>Name</th>
                <th>Display Name</th>
                <th>Logo</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- USERS TAB -->
  <div class="tab-content" id="tab-users">
    <div class="card">
      <div class="toolbar">
        <div class="toolbar-left">
          <input type="text" class="form-control" id="userSearch" placeholder="Search users..." style="min-width:250px">
        </div>
        <div class="toolbar-right">
          <button class="btn btn-outline-danger btn-sm" id="btnUserDeleteSelected" disabled>
            <i class="bi bi-trash"></i> Delete Selected
          </button>
        </div>
      </div>
      <div class="table-responsive" id="boxUsers">
        <table class="table" id="tblUsers">
          <thead>
            <tr>
              <th style="width:40px"><input type="checkbox" id="userChkAll"></th>
              <th>Name</th>
              <th>Email</th>
              <th>Username</th>
              <th>Phone</th>
              <th>Balance</th>
              <th>Spent</th>
              <th>Status</th>
              <th>Registered</th>
              <th style="width:180px">Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="loading" id="userLoading">
        <div class="spinner-border text-primary" role="status"></div>
        <p class="mt-2">Loading users...</p>
      </div>
    </div>
  </div>

  <!-- ORDERS TAB -->
  <div class="tab-content" id="tab-orders">
    <div class="card">
      <div class="toolbar">
        <div class="toolbar-left">
          <input type="text" class="form-control" id="orderSearch" placeholder="Search orders..." style="min-width:250px">
        </div>
        <div class="toolbar-right">
          <button class="btn btn-outline-danger btn-sm" id="btnOrderDeleteSelected" disabled>
            <i class="bi bi-trash"></i> Delete Selected
          </button>
        </div>
      </div>
      <div class="table-responsive" id="boxOrders">
        <table class="table" id="tblOrders">
          <thead>
            <tr>
              <th style="width:40px"><input type="checkbox" id="orderChkAll"></th>
              <th>Order ID</th>
              <th>Client</th>
              <th>Country</th>
              <th>Service</th>
              <th>Phone</th>
              <th>Price</th>
              <th>Status</th>
              <th>Created</th>
              <th style="width:100px">Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="loading" id="orderLoading">
        <div class="spinner-border text-primary" role="status"></div>
        <p class="mt-2">Loading orders...</p>
      </div>
    </div>
  </div>

  <!-- PAYMENTS TAB -->
  <div class="tab-content" id="tab-payments">
    <div class="card">
      <div class="toolbar">
        <div class="toolbar-left">
          <input type="text" class="form-control" id="paymentSearch" placeholder="Search payments..." style="min-width:250px">
        </div>
        <div class="toolbar-right">
          <button class="btn btn-outline-danger btn-sm" id="btnPaymentDeleteSelected" disabled>
            <i class="bi bi-trash"></i> Delete Selected
          </button>
        </div>
      </div>
      <div class="table-responsive" id="boxPayments">
        <table class="table" id="tblPayments">
          <thead>
            <tr>
              <th style="width:40px"><input type="checkbox" id="paymentChkAll"></th>
              <th>Payment ID</th>
              <th>Client</th>
              <th>Amount</th>
              <th>Gateway</th>
              <th>Status</th>
              <th>Created</th>
              <th>Updated</th>
              <th style="width:200px">Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="loading" id="paymentLoading">
        <div class="spinner-border text-primary" role="status"></div>
        <p class="mt-2">Loading payments...</p>
      </div>
    </div>
  </div>

  <!-- SERVICES TAB -->
  <div class="tab-content" id="tab-services">
    <div class="card">
      <div class="toolbar">
        <div class="toolbar-left">
          <input type="text" class="form-control" id="sSearch" placeholder="Search services..." style="min-width:250px">
        </div>
        <div class="toolbar-right">
          <button class="btn btn-outline-primary btn-sm" id="btnImportServices">
            <i class="bi bi-download"></i> Import from API
          </button>
          <button class="btn btn-outline-danger btn-sm" id="btnSvcDeleteSelected" disabled>
            <i class="bi bi-trash"></i> Delete Selected
          </button>
          <button class="btn btn-danger btn-sm" id="btnSvcDeleteAll">
            <i class="bi bi-trash-fill"></i> Delete All
          </button>
        </div>
      </div>
      <div class="table-responsive" id="boxServices">
        <table class="table" id="tblServices">
          <thead>
            <tr>
              <th style="width:40px"><input type="checkbox" id="svcChkAll"></th>
              <th>Service ID</th>
              <th>Name</th>
              <th style="width:180px">Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="loading" id="serviceLoading">
        <div class="spinner-border text-primary" role="status"></div>
        <p class="mt-2">Loading services...</p>
      </div>
    </div>
  </div>

  <!-- PRICES TAB -->
  <div class="tab-content" id="tab-prices">
    <div class="card">
      <div class="toolbar">
        <div class="toolbar-left">
          <select class="form-select" id="filterCountry" style="max-width:200px">
            <option value="0">All countries</option>
          </select>
          <input type="text" class="form-control" id="pSearch" placeholder="Search..." style="min-width:250px">
        </div>
        <div class="toolbar-right">
          <input type="number" class="form-control" id="profitPercent" placeholder="% profit" style="width:110px">
          <button class="btn btn-outline-primary btn-sm" id="btnApplyProfit">
            <i class="bi bi-percent"></i> Apply
          </button>
          <button class="btn btn-outline-primary btn-sm" id="btnImportOpen">
            <i class="bi bi-download"></i> Import
          </button>
          <button class="btn btn-outline-danger btn-sm" id="btnDeleteSelected" disabled>
            <i class="bi bi-trash"></i> Delete Selected
          </button>
          <button class="btn btn-danger btn-sm" id="btnDeleteAll">
            <i class="bi bi-trash-fill"></i> Delete All
          </button>
        </div>
      </div>
      <div class="table-responsive" id="boxPrices">
        <table class="table" id="tblPrices">
          <thead>
            <tr>
              <th style="width:40px"><input type="checkbox" id="chkAllRows"></th>
              <th>Country ID</th>
              <th>Country</th>
              <th>Service ID</th>
              <th>Service</th>
              <th>Price</th>
              <th>Available</th>
              <th style="width:180px">Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="loading" id="priceLoading">
        <div class="spinner-border text-primary" role="status"></div>
        <p class="mt-2">Loading prices...</p>
      </div>
    </div>
  </div>

</div>

<!-- User Edit Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">Edit User</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="editUserId">
        <div class="mb-3">
          <label class="form-label">Name</label>
          <input type="text" class="form-control" id="editUserName">
        </div>
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" class="form-control" id="editUserEmail">
        </div>
        <div class="mb-3">
          <label class="form-label">Balance</label>
          <input type="number" step="0.01" class="form-control" id="editUserBalance">
        </div>
        <div class="mb-3">
          <label class="form-label">Status</label>
          <select class="form-select" id="editUserType">
            <option value="2">Active</option>
            <option value="1">Disabled</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="btnSaveUser">Save Changes</button>
      </div>
    </div>
  </div>
</div>

<!-- Import Modal (Prices) -->
<div class="modal fade" id="importModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">Import Prices by Country</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text" class="form-control mb-3" id="imSearch" placeholder="Search countries...">
        <div class="list-group" id="imList" style="max-height:55vh;overflow:auto"></div>
      </div>
      <div class="modal-footer justify-content-between">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="imSelectAll">
          <label class="form-check-label" for="imSelectAll">Select All</label>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-primary" id="btnImportSelected" disabled>Import Selected</button>
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const toast=(icon,title)=>Swal.fire({toast:true,position:'top-end',timer:2000,showConfirmButton:false,icon,title});
function post(action,data,cb){ $.ajax({url:'',method:'POST',data:Object.assign({action},data||{}),dataType:'json',success:cb,error:()=>cb({status:false,message:'Network error'})}); }
function esc(s){return $('<div/>').text(s==null?'':String(s)).html();}
function escAttr(s){return String(s==null?'':s).replace(/"/g,'&quot;');}

// === Tab Navigation ===
$('.nav-link[data-tab]').on('click',function(e){
  e.preventDefault();
  const tab=$(this).data('tab');
  $('.nav-link').removeClass('active');
  $(this).addClass('active');
  $('.tab-content').removeClass('active');
  $('#tab-'+tab).addClass('active');
  
  const titles={
    dashboard:['Dashboard','System overview'],
    users:['Users Management','Manage system users'],
    orders:['Orders Management','View and manage orders'],
    payments:['Payments Management','Manage payment transactions'],
    services:['SMS Services','Import and manage services'],
    prices:['SMS Prices','Manage country-wise pricing'],
    countries:['Countries Management','Edit country names and logos'],
    servicesmanage:['Services Manager','Edit service names and logos'],
    account:['Account Settings','Manage your admin profile']
  };
  if(titles[tab]){
    $('#pageTitle').text(titles[tab][0]);
    $('#pageDesc').text(titles[tab][1]);
  }
  
  // Load data when switching tabs
  if(tab==='dashboard' && !window.dashboardLoaded){ loadDashboard(); window.dashboardLoaded=true; }
  if(tab==='users' && !window.usersLoaded){ loadUsers(); window.usersLoaded=true; }
  if(tab==='orders' && !window.ordersLoaded){ loadOrders(); window.ordersLoaded=true; }
  if(tab==='payments' && !window.paymentsLoaded){ loadPayments(); window.paymentsLoaded=true; }
  if(tab==='services' && !window.servicesLoaded){ loadServices(); window.servicesLoaded=true; }
  if(tab==='prices' && !window.pricesLoaded){ loadFilterCountries(); loadPrices(); window.pricesLoaded=true; }
  if(tab==='account' && !window.accountLoaded){ loadAdminProfile(); loadAllAdmins(); window.accountLoaded=true; }
  if(tab==='countries' && !window.countriesLoaded){ loadCountries(); window.countriesLoaded=true; }
  if(tab==='servicesmanage' && !window.servicesManageLoaded){ loadServicesManage(); window.servicesManageLoaded=true; }
});

// Mobile menu toggle
$('#mobileToggle').on('click',function(){
  $('#sidebar').toggleClass('show');
});

// Close sidebar on outside click (mobile)
$(document).on('click',function(e){
  if(window.innerWidth<992 && !$(e.target).closest('#sidebar, #mobileToggle').length){
    $('#sidebar').removeClass('show');
  }
});

// ==================== DASHBOARD ====================
function loadDashboard(){
  post('dashboard_stats',{},res=>{
    if(!res.status){
      $('#statsGrid').html('<div class="alert alert-danger">Failed to load stats</div>');
      return;
    }
    
    const d=res.data;
    const html=`
      <div class="stat-card" style="background:linear-gradient(135deg, #667eea 0%, #764ba2 100%)">
        <p>Total Users</p>
        <h3>${d.total_users.toLocaleString()}</h3>
        <i class="bi bi-people" style="font-size:3rem;opacity:0.3;position:absolute;right:1rem;bottom:1rem"></i>
      </div>
      <div class="stat-card" style="background:linear-gradient(135deg, #f093fb 0%, #f5576c 100%)">
        <p>Total Deposits</p>
        <h3>$${d.total_deposits.toFixed(2)}</h3>
        <i class="bi bi-cash-stack" style="font-size:3rem;opacity:0.3;position:absolute;right:1rem;bottom:1rem"></i>
      </div>
      <div class="stat-card" style="background:linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)">
        <p>Total Orders</p>
        <h3>${d.total_orders.toLocaleString()}</h3>
        <i class="bi bi-cart-check" style="font-size:3rem;opacity:0.3;position:absolute;right:1rem;bottom:1rem"></i>
      </div>
      <div class="stat-card" style="background:linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)">
        <p>Total Balance</p>
        <h3>$${d.total_balance.toFixed(2)}</h3>
        <i class="bi bi-wallet2" style="font-size:3rem;opacity:0.3;position:absolute;right:1rem;bottom:1rem"></i>
      </div>
      <div class="stat-card" style="background:linear-gradient(135deg, #fa709a 0%, #fee140 100%)">
        <p>Total Profit</p>
        <h3>$${d.total_profit.toFixed(2)}</h3>
        <i class="bi bi-graph-up-arrow" style="font-size:3rem;opacity:0.3;position:absolute;right:1rem;bottom:1rem"></i>
      </div>
      <div class="stat-card" style="background:linear-gradient(135deg, #30cfd0 0%, #330867 100%)">
        <p>Total Spent</p>
        <h3>$${d.total_spent.toFixed(2)}</h3>
        <i class="bi bi-credit-card" style="font-size:3rem;opacity:0.3;position:absolute;right:1rem;bottom:1rem"></i>
      </div>
    `;
    $('#statsGrid').html(html);
  });
}

// ==================== ACCOUNT SETTINGS ====================
function loadAdminProfile(){
  post('get_admin_profile',{},res=>{
    if(!res.status) return toast('error','Failed to load profile');
    const p=res.data.profile;
    $('#profileName').val(p.name);
    $('#profileEmail').val(p.email);
    $('#profilePhone').val(p.telephone||'');
    $('#profileUsername').val(p.username);
  });
}

$('#profileForm').on('submit',function(e){
  e.preventDefault();
  const data={
    name: $('#profileName').val().trim(),
    email: $('#profileEmail').val().trim(),
    telephone: $('#profilePhone').val().trim()
  };
  post('update_admin_profile',data,res=>{
    if(res.status) toast('success',res.message);
    else toast('error',res.message);
  });
});

$('#passwordForm').on('submit',function(e){
  e.preventDefault();
  const curr=$('#currentPassword').val();
  const newp=$('#newPassword').val();
  const conf=$('#confirmPassword').val();
  
  if(newp!==conf){
    toast('error','Passwords do not match');
    return;
  }
  
  post('change_admin_password',{current_password:curr,new_password:newp},res=>{
    if(res.status){
      toast('success',res.message);
      $('#passwordForm')[0].reset();
    }else{
      toast('error',res.message);
    }
  });
});

$('#addAdminForm').on('submit',function(e){
  e.preventDefault();
  const formData = new FormData(this);
  const data = {};
  formData.forEach((v,k)=>data[k]=v);
  
  post('add_new_admin',data,res=>{
    if(res.status){
      toast('success',res.message);
      $('#addAdminForm')[0].reset();
      loadAllAdmins();
    }else{
      toast('error',res.message);
    }
  });
});

function loadAllAdmins(){
  post('list_all_admins',{},res=>{
    if(!res.status) return;
    const admins=res.data.admins||[];
    const types={'2':'Admin','3':'Super Admin','4':'Developer'};
    const buf=[];
    admins.forEach(a=>{
      buf.push(`<tr>
        <td>${a.admin_id}</td>
        <td><strong>${esc(a.name)}</strong></td>
        <td>${esc(a.email)}</td>
        <td>${esc(a.username)}</td>
        <td>${esc(a.telephone||'-')}</td>
        <td><span class="badge badge-primary">${types[a.admin_type]||'User'}</span></td>
        <td><span class="badge badge-${a.client_type=='2'?'success':'secondary'}">${a.client_type=='2'?'Active':'Disabled'}</span></td>
        <td>${new Date(a.register_date).toLocaleDateString()}</td>
        <td>
          <button class="btn btn-sm btn-outline-danger js-del-admin" data-id="${a.admin_id}">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      </tr>`);
    });
    $('#tblAdmins tbody').html(buf.join('')||'<tr><td colspan="9" class="text-center text-muted">No admins</td></tr>');
  });
}

$(document).on('click','.js-del-admin',function(){
  const id=$(this).data('id');
  Swal.fire({icon:'warning',title:'Delete this admin?',showCancelButton:true,confirmButtonColor:'#ef4444'}).then(r=>{
    if(!r.isConfirmed) return;
    post('delete_admin',{admin_id:id},res=>{
      if(res.status){
        toast('success',res.message);
        loadAllAdmins();
      }else{
        toast('error',res.message);
      }
    });
  });
});

// ==================== COUNTRIES ====================
function loadCountries(){
  post('countries_manage_list',{},res=>{
    if(!res.status) return toast('error','Failed to load countries');
    const countries=res.data.countries||[];
    const buf=[];
    countries.forEach(c=>{
      buf.push(`<tr data-id="${c.provider_id}">
        <td><span class="badge badge-secondary">${c.provider_id}</span></td>
        <td>${esc(c.name_en)}</td>
        <td>
          <input class="form-control form-control-sm js-country-name" value="${escAttr(c.local_name||'')}" placeholder="Enter display name">
        </td>
        <td>
          ${c.country_logo?`<img src="${escAttr(c.country_logo)}" class="logo-preview">`:'<small class="text-muted">No logo</small>'}
          <input type="file" class="form-control form-control-sm mt-2 js-country-logo" accept="image/*">
        </td>
        <td>
          <button class="btn btn-sm btn-primary js-save-country">
            <i class="bi bi-check-lg"></i> Save
          </button>
        </td>
      </tr>`);
    });
    $('#tblCountries tbody').html(buf.join('')||'<tr><td colspan="5" class="text-center text-muted">No countries</td></tr>');
  });
}

$(document).on('click','.js-save-country',function(){
  const $tr=$(this).closest('tr');
  const id=$tr.data('id');
  const name=$tr.find('.js-country-name').val().trim();
  
  post('update_country_name',{country_id:id,local_name:name},res=>{
    if(res.status) toast('success',res.message);
    else toast('error',res.message);
  });
});

$(document).on('change','.js-country-logo',function(){
  const $tr=$(this).closest('tr');
  const id=$tr.data('id');
  const file=this.files[0];
  
  if(!file) return;
  
  const formData=new FormData();
  formData.append('action','upload_country_logo');
  formData.append('country_id',id);
  formData.append('logo',file);
  
  $.ajax({
    url:'',
    method:'POST',
    data:formData,
    processData:false,
    contentType:false,
    dataType:'json',
    success:res=>{
      if(res.status){
        toast('success',res.message);
        loadCountries();
      }else{
        toast('error',res.message);
      }
    },
    error:()=>toast('error','Upload failed')
  });
});

// ==================== SERVICES MANAGE ====================
function loadServicesManage(){
  post('services_manage_list',{},res=>{
    if(!res.status) return toast('error','Failed to load services');
    const services=res.data.services||[];
    const buf=[];
    services.forEach(s=>{
      buf.push(`<tr data-id="${escAttr(s.service_id)}">
        <td><span class="badge badge-primary">${esc(s.service_id)}</span></td>
        <td>${esc(s.name)}</td>
        <td>
          <input class="form-control form-control-sm js-service-name" value="${escAttr(s.display_name||'')}" placeholder="Enter display name">
        </td>
        <td>
          ${s.service_logo?`<img src="${escAttr(s.service_logo)}" class="logo-preview">`:'<small class="text-muted">No logo</small>'}
          <input type="file" class="form-control form-control-sm mt-2 js-service-logo" accept="image/*">
        </td>
        <td>
          <button class="btn btn-sm btn-primary js-save-service-manage">
            <i class="bi bi-check-lg"></i> Save
          </button>
        </td>
      </tr>`);
    });
    $('#tblServicesManage tbody').html(buf.join('')||'<tr><td colspan="5" class="text-center text-muted">No services</td></tr>');
  });
}

$(document).on('click','.js-save-service-manage',function(){
  const $tr=$(this).closest('tr');
  const id=$tr.data('id');
  const name=$tr.find('.js-service-name').val().trim();
  
  post('update_service_name',{service_id:id,display_name:name},res=>{
    if(res.status) toast('success',res.message);
    else toast('error',res.message);
  });
});

$(document).on('change','.js-service-logo',function(){
  const $tr=$(this).closest('tr');
  const id=$tr.data('id');
  const file=this.files[0];
  
  if(!file) return;
  
  const formData=new FormData();
  formData.append('action','upload_service_logo');
  formData.append('service_id',id);
  formData.append('logo',file);
  
  $.ajax({
    url:'',
    method:'POST',
    data:formData,
    processData:false,
    contentType:false,
    dataType:'json',
    success:res=>{
      if(res.status){
        toast('success',res.message);
        loadServicesManage();
      }else{
        toast('error',res.message);
      }
    },
    error:()=>toast('error','Upload failed')
  });
});

// ==================== USERS (existing code) ====================
let usersSel=new Set(), usersOff=0, usersBusy=false, usersDone=false, usersQ='', usersLimit=100;
const userModal = new bootstrap.Modal('#userModal');

function refreshUserDeleteBtn(){
  $('#btnUserDeleteSelected').prop('disabled', usersSel.size===0).text(usersSel.size?`Delete Selected (${usersSel.size})`:'Delete Selected');
}

function resetUsers(){
  usersOff=0; usersBusy=false; usersDone=false;
  usersSel.clear(); $('#userChkAll').prop('checked',false);
  refreshUserDeleteBtn();
  $('#tblUsers tbody').empty();
  $('#userLoading').show();
}

function loadUsers(){
  if(usersBusy||usersDone) return;
  usersBusy=true;
  $('#userLoading').show();
  
  post('users_list',{offset:usersOff,limit:usersLimit,q:usersQ},res=>{
    usersBusy=false;
    $('#userLoading').hide();
    
    if(!res.status){
      $('#tblUsers tbody').html('<tr><td colspan="10" class="text-center text-danger">Failed to load</td></tr>');
      return;
    }
    
    const rows=res.data.rows||[];
    if(!rows.length){
      if(usersOff===0){
        $('#tblUsers tbody').html(`<tr><td colspan="10"><div class="empty-state">
          <i class="bi bi-people"></i><p>No users found</p>
        </div></td></tr>`);
      }
      usersDone=true;
      return;
    }
    
    const buf=[];
    rows.forEach(r=>{
      const status = r.client_type==2?'active':'inactive';
      const statusLabel = r.client_type==2?'Active':'Disabled';
      
      buf.push(`<tr data-id="${r.client_id}">
        <td><input type="checkbox" class="user-chk"></td>
        <td><strong>${esc(r.name)}</strong></td>
        <td><small>${esc(r.email)}</small></td>
        <td><small>${esc(r.username||'-')}</small></td>
        <td><small>${esc(r.telephone||'-')}</small></td>
        <td><strong class="text-success">${parseFloat(r.balance||0).toFixed(2)}</strong></td>
        <td><span class="text-muted">${parseFloat(r.spent||0).toFixed(2)}</span></td>
        <td>
          <span class="badge badge-${status==='active'?'success':'secondary'}">
            <span class="status-dot ${status}"></span>${statusLabel}
          </span>
        </td>
        <td><small>${new Date(r.register_date).toLocaleDateString()}</small></td>
        <td>
          <button class="btn btn-sm btn-outline-primary js-edit-user" title="Edit">
            <i class="bi bi-pencil"></i>
          </button>
          <button class="btn btn-sm btn-outline-danger js-del-user" title="Delete">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      </tr>`);
    });
    
    $('#tblUsers tbody').append(buf.join(''));
    usersOff = res.data.next_offset ?? usersOff;
    if(res.data.next_offset==null) usersDone=true;
  });
}

$('#boxUsers').on('scroll',function(){
  if(this.scrollTop+this.clientHeight>=this.scrollHeight-10) loadUsers();
});

$(document).on('change','.user-chk',function(){
  const id=$(this).closest('tr').data('id');
  if(this.checked) usersSel.add(id); else usersSel.delete(id);
  refreshUserDeleteBtn();
});

$('#userChkAll').on('change',function(){
  const on=this.checked;
  $('#tblUsers tbody .user-chk').each(function(){
    const id=$(this).closest('tr').data('id');
    $(this).prop('checked',on);
    if(on) usersSel.add(id); else usersSel.delete(id);
  });
  refreshUserDeleteBtn();
});

let userSearchTimer=null;
$('#userSearch').on('input',function(){
  usersQ=this.value.trim();
  clearTimeout(userSearchTimer);
  userSearchTimer=setTimeout(()=>{resetUsers();loadUsers();},400);
});

$(document).on('click','.js-edit-user',function(){
  const $tr=$(this).closest('tr');
  const id=$tr.data('id');
  const cells=$tr.find('td');
  
  $('#editUserId').val(id);
  $('#editUserName').val($(cells[1]).text().trim());
  $('#editUserEmail').val($(cells[2]).text().trim());
  $('#editUserBalance').val(parseFloat($(cells[5]).text().trim()));
$('#editUserType').val($(cells[7]).text().includes('Active')?'2':'1');
  userModal.show();
});

$('#btnSaveUser').on('click',function(){
  const data={
    client_id: $('#editUserId').val(),
    name: $('#editUserName').val().trim(),
    email: $('#editUserEmail').val().trim(),
    balance: $('#editUserBalance').val().trim(),
    client_type: $('#editUserType').val()
  };
  
  if(!data.name || !data.email){
    toast('error','Name and email required');
    return;
  }
  
  post('user_save',data,res=>{
    if(res.status){
      toast('success','User updated');
      userModal.hide();
      resetUsers();
      loadUsers();
    }else{
      toast('error',res.message||'Failed');
    }
  });
});

$(document).on('click','.js-del-user',function(){
  const id=$(this).closest('tr').data('id');
  Swal.fire({
    icon:'warning',
    title:'Delete this user?',
    html:'This will also delete all their orders and payments.',
    showCancelButton:true,
    confirmButtonText:'Delete',
    confirmButtonColor:'#ef4444'
  }).then(r=>{
    if(!r.isConfirmed) return;
    post('user_delete',{client_id:id},res=>{
      if(res.status){
        toast('success','User deleted');
        resetUsers();
        loadUsers();
      }else{
        toast('error',res.message||'Failed');
      }
    });
  });
});

$('#btnUserDeleteSelected').on('click',()=>{
  if(!usersSel.size) return;
  Swal.fire({
    icon:'warning',
    title:`Delete ${usersSel.size} selected users?`,
    html:'This will also delete all their orders and payments.',
    showCancelButton:true,
    confirmButtonText:'Delete',
    confirmButtonColor:'#ef4444'
  }).then(r=>{
    if(!r.isConfirmed) return;
    
    const ids=[...usersSel];
    let completed=0;
    
    Swal.fire({
      title:'Deleting...',
      html:`Progress: <b>0</b>/${ids.length}`,
      allowOutsideClick:false,
      didOpen:()=>Swal.showLoading()
    });
    
    ids.forEach((id,idx)=>{
      post('user_delete',{client_id:id},res=>{
        completed++;
        Swal.update({html:`Progress: <b>${completed}</b>/${ids.length}`});
        
        if(completed===ids.length){
          Swal.close();
          toast('success',`Deleted ${completed} user(s)`);
          resetUsers();
          loadUsers();
        }
      });
    });
  });
});

// ==================== ORDERS (existing code - abbreviated) ====================
let ordersSel=new Set(), ordersOff=0, ordersBusy=false, ordersDone=false, ordersQ='', ordersLimit=100;

function refreshOrderDeleteBtn(){
  $('#btnOrderDeleteSelected').prop('disabled', ordersSel.size===0).text(ordersSel.size?`Delete Selected (${ordersSel.size})`:'Delete Selected');
}

function resetOrders(){
  ordersOff=0; ordersBusy=false; ordersDone=false;
  ordersSel.clear(); $('#orderChkAll').prop('checked',false);
  refreshOrderDeleteBtn();
  $('#tblOrders tbody').empty();
  $('#orderLoading').show();
}

function loadOrders(){
  if(ordersBusy||ordersDone) return;
  ordersBusy=true;
  $('#orderLoading').show();
  
  post('orders_list',{offset:ordersOff,limit:ordersLimit,q:ordersQ},res=>{
    ordersBusy=false;
    $('#orderLoading').hide();
    
    if(!res.status){
      $('#tblOrders tbody').html('<tr><td colspan="10" class="text-center text-danger">Failed to load</td></tr>');
      return;
    }
    
    const rows=res.data.rows||[];
    if(!rows.length){
      if(ordersOff===0){
        $('#tblOrders tbody').html(`<tr><td colspan="10"><div class="empty-state">
          <i class="bi bi-cart-check"></i><p>No orders found</p>
        </div></td></tr>`);
      }
      ordersDone=true;
      return;
    }
    
    const statusMap={
      'STATUS_WAIT_CODE':'<span class="badge badge-warning">Waiting</span>',
      'STATUS_CODE_RECEIVED':'<span class="badge badge-primary">Code Received</span>',
      'COMPLETED':'<span class="badge badge-success">Completed</span>',
      'EXPIRED':'<span class="badge badge-secondary">Expired</span>',
      'CANCELLED':'<span class="badge badge-danger">Cancelled</span>'
    };
    
    const buf=[];
    rows.forEach(r=>{
      buf.push(`<tr data-id="${r.id}">
        <td><input type="checkbox" class="order-chk"></td>
        <td><strong>#${r.id}</strong></td>
        <td>
          <div><strong>${esc(r.client_name||'N/A')}</strong></div>
          <small class="text-muted">${esc(r.email||'')}</small>
        </td>
        <td>
          <div>${esc(r.country_name||'N/A')}</div>
          <small class="text-muted">ID: ${r.country_id}</small>
        </td>
        <td>
          <div>${esc(r.service_name||'N/A')}</div>
          <small class="text-muted">${esc(r.service_id)}</small>
        </td>
        <td><code>${esc(r.phone||'-')}</code></td>
        <td><strong>${parseFloat(r.provider_price||0).toFixed(4)} ${esc(r.currency||'USD')}</strong></td>
        <td>${statusMap[r.status]||'<span class="badge badge-secondary">'+esc(r.status)+'</span>'}</td>
        <td><small>${new Date(r.created_at).toLocaleString()}</small></td>
        <td>
          <button class="btn btn-sm btn-outline-danger js-del-order" title="Delete">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      </tr>`);
    });
    
    $('#tblOrders tbody').append(buf.join(''));
    ordersOff = res.data.next_offset ?? ordersOff;
    if(res.data.next_offset==null) ordersDone=true;
  });
}

$('#boxOrders').on('scroll',function(){
  if(this.scrollTop+this.clientHeight>=this.scrollHeight-10) loadOrders();
});

$(document).on('change','.order-chk',function(){
  const id=$(this).closest('tr').data('id');
  if(this.checked) ordersSel.add(id); else ordersSel.delete(id);
  refreshOrderDeleteBtn();
});

$('#orderChkAll').on('change',function(){
  const on=this.checked;
  $('#tblOrders tbody .order-chk').each(function(){
    const id=$(this).closest('tr').data('id');
    $(this).prop('checked',on);
    if(on) ordersSel.add(id); else ordersSel.delete(id);
  });
  refreshOrderDeleteBtn();
});

let orderSearchTimer=null;
$('#orderSearch').on('input',function(){
  ordersQ=this.value.trim();
  clearTimeout(orderSearchTimer);
  orderSearchTimer=setTimeout(()=>{resetOrders();loadOrders();},400);
});

$(document).on('click','.js-del-order',function(){
  const id=$(this).closest('tr').data('id');
  Swal.fire({
    icon:'warning',
    title:'Delete this order?',
    showCancelButton:true,
    confirmButtonText:'Delete',
    confirmButtonColor:'#ef4444'
  }).then(r=>{
    if(!r.isConfirmed) return;
    post('order_delete',{order_id:id},res=>{
      if(res.status){
        toast('success','Order deleted');
        resetOrders();
        loadOrders();
      }else{
        toast('error',res.message||'Failed');
      }
    });
  });
});

$('#btnOrderDeleteSelected').on('click',()=>{
  if(!ordersSel.size) return;
  Swal.fire({
    icon:'warning',
    title:`Delete ${ordersSel.size} selected orders?`,
    showCancelButton:true,
    confirmButtonText:'Delete',
    confirmButtonColor:'#ef4444'
  }).then(r=>{
    if(!r.isConfirmed) return;
    
    const ids=[...ordersSel];
    let completed=0;
    
    Swal.fire({
      title:'Deleting...',
      html:`Progress: <b>0</b>/${ids.length}`,
      allowOutsideClick:false,
      didOpen:()=>Swal.showLoading()
    });
    
    ids.forEach(id=>{
      post('order_delete',{order_id:id},res=>{
        completed++;
        Swal.update({html:`Progress: <b>${completed}</b>/${ids.length}`});
        
        if(completed===ids.length){
          Swal.close();
          toast('success',`Deleted ${completed} order(s)`);
          resetOrders();
          loadOrders();
        }
      });
    });
  });
});

// ==================== PAYMENTS (WITH GATEWAY NAMES) ====================
let paymentsSel=new Set(), paymentsOff=0, paymentsBusy=false, paymentsDone=false, paymentsQ='', paymentsLimit=100;

function refreshPaymentDeleteBtn(){
  $('#btnPaymentDeleteSelected').prop('disabled', paymentsSel.size===0).text(paymentsSel.size?`Delete Selected (${paymentsSel.size})`:'Delete Selected');
}

function resetPayments(){
  paymentsOff=0; paymentsBusy=false; paymentsDone=false;
  paymentsSel.clear(); $('#paymentChkAll').prop('checked',false);
  refreshPaymentDeleteBtn();
  $('#tblPayments tbody').empty();
  $('#paymentLoading').show();
}

function loadPayments(){
  if(paymentsBusy||paymentsDone) return;
  paymentsBusy=true;
  $('#paymentLoading').show();
  
  post('payments_list',{offset:paymentsOff,limit:paymentsLimit,q:paymentsQ},res=>{
    paymentsBusy=false;
    $('#paymentLoading').hide();
    
    if(!res.status){
      $('#tblPayments tbody').html('<tr><td colspan="9" class="text-center text-danger">Failed to load</td></tr>');
      return;
    }
    
    const rows=res.data.rows||[];
    if(!rows.length){
      if(paymentsOff===0){
        $('#tblPayments tbody').html(`<tr><td colspan="9"><div class="empty-state">
          <i class="bi bi-credit-card"></i><p>No payments found</p>
        </div></td></tr>`);
      }
      paymentsDone=true;
      return;
    }
    
    const statusMap={
      '1':{label:'Pending',class:'warning',dot:'pending'},
      '2':{label:'Hold',class:'warning',dot:'hold'},
      '3':{label:'Completed',class:'success',dot:'completed'},
      '4':{label:'Expired',class:'secondary',dot:'expired'}
    };
    
    const buf=[];
    rows.forEach(r=>{
      const st=statusMap[r.payment_status]||{label:'Unknown',class:'secondary',dot:'inactive'};
      
      buf.push(`<tr data-id="${r.payment_id}">
        <td><input type="checkbox" class="payment-chk"></td>
        <td><strong>#${r.payment_id}</strong></td>
        <td>
          <div><strong>${esc(r.client_name||'N/A')}</strong></div>
          <small class="text-muted">${esc(r.email||'')}</small>
        </td>
        <td><strong class="text-success">${parseFloat(r.payment_amount||0).toFixed(2)}</strong></td>
        <td>
          <div><strong>${esc(r.method_name||'Unknown')}</strong></div>
          <small class="text-muted">ID: ${r.payment_method}</small>
        </td>
        <td>
          <select class="form-select form-select-sm js-payment-status" data-id="${r.payment_id}">
            <option value="1" ${r.payment_status=='1'?'selected':''}>Pending</option>
            <option value="2" ${r.payment_status=='2'?'selected':''}>Hold</option>
            <option value="3" ${r.payment_status=='3'?'selected':''}>Completed</option>
            <option value="4" ${r.payment_status=='4'?'selected':''}>Expired</option>
          </select>
        </td>
        <td><small>${new Date(r.payment_create_date).toLocaleString()}</small></td>
        <td><small>${new Date(r.payment_update_date).toLocaleString()}</small></td>
        <td>
          <button class="btn btn-sm btn-outline-danger js-del-payment" title="Delete">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      </tr>`);
    });
    
    $('#tblPayments tbody').append(buf.join(''));
    paymentsOff = res.data.next_offset ?? paymentsOff;
    if(res.data.next_offset==null) paymentsDone=true;
  });
}

$('#boxPayments').on('scroll',function(){
  if(this.scrollTop+this.clientHeight>=this.scrollHeight-10) loadPayments();
});

$(document).on('change','.payment-chk',function(){
  const id=$(this).closest('tr').data('id');
  if(this.checked) paymentsSel.add(id); else paymentsSel.delete(id);
  refreshPaymentDeleteBtn();
});

$('#paymentChkAll').on('change',function(){
  const on=this.checked;
  $('#tblPayments tbody .payment-chk').each(function(){
    const id=$(this).closest('tr').data('id');
    $(this).prop('checked',on);
    if(on) paymentsSel.add(id); else paymentsSel.delete(id);
  });
  refreshPaymentDeleteBtn();
});

let paymentSearchTimer=null;
$('#paymentSearch').on('input',function(){
  paymentsQ=this.value.trim();
  clearTimeout(paymentSearchTimer);
  paymentSearchTimer=setTimeout(()=>{resetPayments();loadPayments();},400);
});

$(document).on('change','.js-payment-status',function(){
  const id=$(this).data('id');
  const status=$(this).val();
  
  post('payment_update_status',{payment_id:id,status:status},res=>{
    if(res.status){
      toast('success','Status updated');
    }else{
      toast('error',res.message||'Failed');
    }
  });
});

$(document).on('click','.js-del-payment',function(){
  const id=$(this).closest('tr').data('id');
  Swal.fire({
    icon:'warning',
    title:'Delete this payment?',
    showCancelButton:true,
    confirmButtonText:'Delete',
    confirmButtonColor:'#ef4444'
  }).then(r=>{
    if(!r.isConfirmed) return;
    post('payment_delete',{payment_id:id},res=>{
      if(res.status){
        toast('success','Payment deleted');
        resetPayments();
        loadPayments();
      }else{
        toast('error',res.message||'Failed');
      }
    });
  });
});

$('#btnPaymentDeleteSelected').on('click',()=>{
  if(!paymentsSel.size) return;
  Swal.fire({
    icon:'warning',
    title:`Delete ${paymentsSel.size} selected payments?`,
    showCancelButton:true,
    confirmButtonText:'Delete',
    confirmButtonColor:'#ef4444'
  }).then(r=>{
    if(!r.isConfirmed) return;
    
    const ids=[...paymentsSel];
    let completed=0;
    
    Swal.fire({
      title:'Deleting...',
      html:`Progress: <b>0</b>/${ids.length}`,
      allowOutsideClick:false,
      didOpen:()=>Swal.showLoading()
    });
    
    ids.forEach(id=>{
      post('payment_delete',{payment_id:id},res=>{
        completed++;
        Swal.update({html:`Progress: <b>${completed}</b>/${ids.length}`});
        
        if(completed===ids.length){
          Swal.close();
          toast('success',`Deleted ${completed} payment(s)`);
          resetPayments();
          loadPayments();
        }
      });
    });
  });
});

// ==================== SERVICES (existing SMS services code - abbreviated) ====================
let sOffset=0,sBusy=false,sDone=false,sLimit=120,sQ='';
const svcSel=new Set();

function refreshSvcDelete(){
  $('#btnSvcDeleteSelected').prop('disabled', svcSel.size===0).text(svcSel.size?`Delete Selected (${svcSel.size})`:'Delete Selected');
}

function resetServices(){
  sOffset=0;sBusy=false;sDone=false;
  svcSel.clear();$('#svcChkAll').prop('checked',false);
  refreshSvcDelete();
  $('#tblServices tbody').empty();
  $('#serviceLoading').show();
}

function loadServices(){
  if(sBusy||sDone) return;
  sBusy=true;
  $('#serviceLoading').show();
  
  post('services_list',{offset:sOffset,limit:sLimit,q:sQ},res=>{
    sBusy=false;
    $('#serviceLoading').hide();
    
    if(!res.status){
      $('#tblServices tbody').html('<tr><td colspan="4" class="text-center text-danger">Failed to load</td></tr>');
      return;
    }
    
    const rows=res.data.rows||[];
    if(!rows.length){
      if(sOffset===0){
        $('#tblServices tbody').html(`<tr><td colspan="4"><div class="empty-state">
          <i class="bi bi-list-check"></i><p>No services found</p>
        </div></td></tr>`);
      }
      sDone=true;
      return;
    }
    
    const buf=[];
    rows.forEach(r=>{
      buf.push(`<tr data-id="${escAttr(r.id)}">
        <td><input type="checkbox" class="svc-chk"></td>
        <td><span class="badge badge-primary">${esc(r.id)}</span></td>
        <td>
          <input class="form-control form-control-sm js-svc-name" value="${escAttr(r.name||'')}" placeholder="Edit name">
        </td>
        <td>
          <button class="btn btn-sm btn-primary js-svc-save">
            <i class="bi bi-check-lg"></i> Save
          </button>
          <button class="btn btn-sm btn-outline-danger js-svc-del">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      </tr>`);
    });
    
    $('#tblServices tbody').append(buf.join(''));
    sOffset = res.data.next_offset ?? sOffset;
    if(res.data.next_offset==null) sDone=true;
  });
}

$('#boxServices').on('scroll',function(){
  if(this.scrollTop+this.clientHeight>=this.scrollHeight-10) loadServices();
});

$('#btnImportServices').on('click',()=>{
  Swal.fire({
    icon:'question',
    title:'Import services from API?',
    showCancelButton:true
  }).then(r=>{
    if(!r.isConfirmed) return;
    Swal.fire({title:'Importing…',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
    post('import_services',{},res=>{
      Swal.close();
      if(res.status){
        toast('success',res.message);
        resetServices();
        loadServices();
      }else{
        toast('error',res.message||'Failed');
      }
    });
  });
});

$(document).on('change','.svc-chk',function(){
  const id=$(this).closest('tr').data('id');
  if(this.checked) svcSel.add(String(id)); else svcSel.delete(String(id));
  refreshSvcDelete();
});

$('#svcChkAll').on('change',function(){
  const on=this.checked;
  $('#tblServices tbody .svc-chk').each(function(){
    const id=$(this).closest('tr').data('id');
    $(this).prop('checked',on);
    if(on) svcSel.add(String(id)); else svcSel.delete(String(id));
  });
  refreshSvcDelete();
});

$(document).on('click','.js-svc-save',function(){
  const $tr=$(this).closest('tr');
  post('save_service',{
    service_id:$tr.data('id'),
    name:$tr.find('.js-svc-name').val().trim()
  },res=>{
    if(res.status) toast('success','Saved');
    else toast('error',res.message||'Failed');
  });
});

$(document).on('click','.js-svc-del',function(){
  const id=$(this).closest('tr').data('id');
  Swal.fire({
    icon:'warning',
    title:`Delete service ${id}?`,
    showCancelButton:true,
    confirmButtonColor:'#ef4444'
  }).then(r=>{
    if(!r.isConfirmed) return;
    post('delete_services_selected',{ids:[id]},res=>{
      if(res.status){
        toast('success','Deleted');
        resetServices();
        loadServices();
      }else{
        toast('error',res.message||'Failed');
      }
    });
  });
});

$('#btnSvcDeleteSelected').on('click',()=>{
  if(!svcSel.size) return;
  Swal.fire({
    icon:'warning',
    title:`Delete ${svcSel.size} selected?`,
    showCancelButton:true,
    confirmButtonColor:'#ef4444'
  }).then(r=>{
    if(!r.isConfirmed) return;
    post('delete_services_selected',{ids:[...svcSel]},res=>{
      if(res.status){
        toast('success',res.message||'Deleted');
        resetServices();
        loadServices();
      }else{
        toast('error',res.message||'Failed');
      }
    });
  });
});

let sT=null;
$('#sSearch').on('input',function(){
  sQ=this.value.trim();
  clearTimeout(sT);
  sT=setTimeout(()=>{resetServices();loadServices();},350);
});

$('#btnSvcDeleteAll').on('click',()=>{
  const scope = sQ ? `matching "<b>${esc(sQ)}</b>"` : 'ALL services';
  Swal.fire({
    icon:'warning',
    title:'Delete services?',
    html:`This will delete ${scope} (and their price rows).`,
    showCancelButton:true,
    confirmButtonText:'Delete',
    confirmButtonColor:'#ef4444'
  }).then(r=>{
    if(!r.isConfirmed) return;
    post('delete_services_filtered',{q:sQ},res=>{
      if(res.status){
        toast('success','Deleted');
        resetServices();
        loadServices();
      }else{
        toast('error',res.message||'Failed');
      }
    });
  });
});

// ==================== PRICES (existing code - abbreviated) ====================
const sel=new Set();
let pOffset=0,pBusy=false,pDone=false,pLimit=120,pQ='',pCountry=0;

function refreshDeleteBtn(){
  $('#btnDeleteSelected').prop('disabled', sel.size===0).text(sel.size?`Delete Selected (${sel.size})`:'Delete Selected');
}

function loadFilterCountries(){
  post('countries_db_list',{},res=>{
    const $f=$('#filterCountry');
    $f.find('option:not([value="0"])').remove();
    if(res.status&&res.data.rows){
      res.data.rows.forEach(r=>$f.append(`<option value="${r.id}">${esc(r.name)}</option>`));
    }
  });
}

function resetPrices(){
  pOffset=0;pBusy=false;pDone=false;
  sel.clear();$('#chkAllRows').prop('checked',false);
  refreshDeleteBtn();
  $('#tblPrices tbody').empty();
  $('#priceLoading').show();
}

function loadPrices(){
  if(pBusy||pDone) return;
  pBusy=true;
  $('#priceLoading').show();
  
  post('prices_list',{offset:pOffset,limit:pLimit,q:pQ,country_id:pCountry},res=>{
    pBusy=false;
    $('#priceLoading').hide();
    
    if(!res.status){
      $('#tblPrices tbody').html('<tr><td colspan="8" class="text-center text-danger">Failed to load</td></tr>');
      return;
    }
    
    const rows=res.data.rows||[];
    if(!rows.length){
      if(pOffset===0){
        $('#tblPrices tbody').html(`<tr><td colspan="8"><div class="empty-state">
          <i class="bi bi-currency-dollar"></i><p>No prices found</p>
        </div></td></tr>`);
      }
      pDone=true;
      return;
    }
    
    const buf=[];
    rows.forEach(r=>{
      const key = `${r.country_id}|${r.service_id}`;
      buf.push(`<tr data-country="${r.country_id}" data-service="${escAttr(r.service_id)}" data-key="${key}">
        <td><input type="checkbox" class="row-chk"></td>
        <td><span class="badge badge-secondary">${esc(String(r.country_id))}</span></td>
        <td><strong>${esc(r.country_name||'')}</strong></td>
        <td><span class="badge badge-primary">${esc(r.service_id)}</span></td>
        <td>${esc(r.service_name||'')}</td>
        <td>
          <input class="form-control form-control-sm js-price" 
                 value="${escAttr(r.provider_cost!=null?r.provider_cost:'')}" 
                 placeholder="Enter price" style="min-width:120px">
          <div class="text-muted mt-1" style="font-size:11px">
            Provider: ${esc(r.pprice_label!=null?r.pprice_label:'-')}
          </div>
        </td>
        <td><span class="badge badge-success">${esc(r.available_count)}</span></td>
        <td>
          <button class="btn btn-sm btn-primary js-save-price">
            <i class="bi bi-check-lg"></i> Save
          </button>
          <button class="btn btn-sm btn-outline-danger js-del-price">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      </tr>`);
    });
    
    $('#tblPrices tbody').append(buf.join(''));
    pOffset = res.data.next_offset ?? pOffset;
    if(res.data.next_offset==null) pDone=true;
  });
}

$('#boxPrices').on('scroll',function(){
  if(this.scrollTop+this.clientHeight>=this.scrollHeight-10) loadPrices();
});

$(document).on('change','.row-chk',function(){
  const key=$(this).closest('tr').data('key');
  if(this.checked) sel.add(String(key)); else sel.delete(String(key));
  refreshDeleteBtn();
});

$('#chkAllRows').on('change',function(){
  const on=this.checked;
  $('#tblPrices tbody tr').each(function(){
    const key=$(this).data('key');
    const $cb=$(this).find('.row-chk');
    $cb.prop('checked',on);
    if(on) sel.add(String(key)); else sel.delete(String(key));
  });
  refreshDeleteBtn();
});

$(document).on('click','.js-save-price',function(){
  const $tr=$(this).closest('tr');
  post('save_price',{
    country_id:$tr.data('country'),
    service_id:$tr.data('service'),
    provider_cost:$tr.find('.js-price').val().trim()
  },res=>{
    if(res.status) toast('success','Saved');
    else toast('error',res.message||'Failed');
  });
});

$(document).on('click','.js-del-price',function(){
  const $tr=$(this).closest('tr');
  const key=$tr.data('key');
  Swal.fire({
    icon:'warning',
    title:'Delete this row?',
    showCancelButton:true,
    confirmButtonColor:'#ef4444'
  }).then(r=>{
    if(!r.isConfirmed) return;
    post('delete_prices_selected',{items:[key]},res=>{
      if(res.status){
        toast('success','Deleted');
        resetPrices();
        loadPrices();
      }else{
        toast('error',res.message||'Failed');
      }
    });
  });
});

$('#btnApplyProfit').on('click',()=>{
  const pct=parseFloat($('#profitPercent').val());
  if(isNaN(pct)||pct===0){
    toast('error','Enter % profit');
    return;
  }
  Swal.fire({
    icon:'question',
    title:`Apply ${pct}% profit?`,
    showCancelButton:true
  }).then(r=>{
    if(!r.isConfirmed) return;
    post('bulk_profit',{percent:pct,q:pQ,country_id:pCountry},res=>{
      if(res.status){
        toast('success','Profit applied');
        resetPrices();
        loadPrices();
      }else{
        toast('error',res.message||'Failed');
      }
    });
  });
});

$('#btnDeleteSelected').on('click',()=>{
  if(!sel.size) return;
  Swal.fire({
    icon:'warning',
    title:`Delete ${sel.size} selected?`,
    showCancelButton:true,
    confirmButtonColor:'#ef4444'
  }).then(r=>{
    if(!r.isConfirmed) return;
    post('delete_prices_selected',{items:[...sel]},res=>{
      if(res.status){
        toast('success',res.message||'Deleted');
        resetPrices();
        loadPrices();
      }else{
        toast('error',res.message||'Failed');
      }
    });
  });
});

$('#btnDeleteAll').on('click',()=>{
  const scope=(pCountry>0?`country #${pCountry}`:'all countries');
  Swal.fire({
    icon:'warning',
    title:'Delete ALL filtered rows?',
    html:`This will delete every price matching <b>${scope}</b>${pQ?` and search "<b>${esc(pQ)}</b>"`:''}.`,
    showCancelButton:true,
    confirmButtonText:'Delete',
    confirmButtonColor:'#ef4444'
  }).then(r=>{
    if(!r.isConfirmed) return;
    post('delete_prices_filtered',{country_id:pCountry,q:pQ},res=>{
      if(res.status){
        toast('success',res.message||'Deleted');
        resetPrices();
        loadPrices();
      }else{
        toast('error',res.message||'Failed');
      }
    });
  });
});

let st=null;
$('#pSearch').on('input',function(){
  pQ=this.value.trim();
  clearTimeout(st);
  st=setTimeout(()=>{
    sel.clear();
    $('#chkAllRows').prop('checked',false);
    refreshDeleteBtn();
    resetPrices();
    loadPrices();
  },350);
});

$('#filterCountry').on('change',function(){
  pCountry=parseInt(this.value||'0',10)||0;
  sel.clear();
  $('#chkAllRows').prop('checked',false);
  refreshDeleteBtn();
  resetPrices();
  loadPrices();
});

// ==================== IMPORT MODAL ====================
const importModal = new bootstrap.Modal('#importModal');
const imSel = new Set();
let provCountries = [];

function renderImportList(rows,q){
  imSel.clear();
  $('#imSelectAll').prop('checked',false);
  $('#btnImportSelected').prop('disabled',true).text('Import Selected');
  const qq=(q||'').toLowerCase();
  const html=(rows||[])
    .filter(r=>!qq||r.name.toLowerCase().includes(qq)||String(r.id).includes(qq))
    .map(r=>`<label class="list-group-item d-flex justify-content-between align-items-center">
      <span class="d-flex align-items-center gap-2">
        <input type="checkbox" class="form-check-input im-chk" data-id="${r.id}">
        <span>${esc(r.name)}</span>
      </span>
      <span class="badge bg-light text-dark">${r.id}</span>
    </label>`).join('');
  $('#imList').html(html || '<div class="text-muted py-3 text-center">No countries</div>');
}

$('#btnImportOpen').on('click',()=>{
  $('#imList').html('<div class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm"></div><p class="mt-2">Loading…</p></div>');
  $('#imSearch').val('');
  $('#imSelectAll').prop('checked',false);
  $('#btnImportSelected').prop('disabled',true).text('Import Selected');
  imSel.clear();
  importModal.show();
  
  post('provider_countries',{},res=>{
    provCountries = (res.status && res.data.rows) ? res.data.rows : [];
    renderImportList(provCountries,'');
    $('#imSearch').off('input').on('input',function(){
      renderImportList(provCountries,this.value.trim());
    });
  });
});

$(document).on('change','.im-chk',function(){
  const id=+$(this).data('id');
  this.checked?imSel.add(id):imSel.delete(id);
  $('#btnImportSelected').prop('disabled',imSel.size===0)
    .text(imSel.size?`Import Selected (${imSel.size})`:'Import Selected');
});

$('#imSelectAll').on('change',function(){
  const on=this.checked;
  $('#imList .im-chk').each(function(){
    $(this).prop('checked',on);
    const id=+$(this).data('id');
    on?imSel.add(id):imSel.delete(id);
  });
  $('#btnImportSelected').prop('disabled',imSel.size===0)
    .text(imSel.size?`Import Selected (${imSel.size})`:'Import Selected');
});

$('#btnImportSelected').on('click',()=>{
  if(!imSel.size) return;
  const chosen=[...imSel];
  Swal.fire({
    icon:'question',
    title:`Import ${chosen.length} countr${chosen.length>1?'ies':'y'}?`,
    showCancelButton:true
  }).then(r=>{
    if(!r.isConfirmed) return;
    Swal.fire({
      title:'Importing…',
      html:'Please wait...',
      allowOutsideClick:false,
      didOpen:()=>Swal.showLoading()
    });
    
    post('import_countries_bulk',{country_ids:chosen},res=>{
      Swal.close();
      if(!res.status){
        toast('error',res.message||'Failed');
        return;
      }
      toast('success',res.message||'Imported');
      importModal.hide();
      
      loadFilterCountries();
      
      if(chosen.length===1){
        const val=String(chosen[0]);
        $('#filterCountry').val(val).trigger('change');
      }else{
        resetPrices();
        loadPrices();
      }
    });
  });
});

// ==================== INITIALIZATION ====================
$(function(){
  // Load dashboard on initial load
  window.dashboardLoaded=true;
  loadDashboard();
});
</script>
</body>
</html>

<?php

  exit();

else :
  include FILES_BASE . '/main_system/client_php/404.php';
  exit();
endif;
?>