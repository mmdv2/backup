<?php
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/utils.php';

$rootDirectory = dirname(__DIR__) . '/';
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
$domain = $protocol . $_SERVER['HTTP_HOST'];
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
$cron_path = $script_dir . '/cron_backup.php'; 
$cron_command = 'curl ' . $domain . $cron_path;

$default_upload_path = __DIR__ . '/backups';
if (!file_exists($default_upload_path)) {
    mkdir($default_upload_path, 0755, true);
}

$uPOST = sanitizeInput($_POST);

$ERROR = [];
$SUCCESS = [];

$dbname = $uPOST['dbname'] ?? '';
$usernamedb = $uPOST['usernamedb'] ?? '';
$passworddb = $uPOST['passworddb'] ?? '';
$zip_filename = $uPOST['zip_filename'] ?? 'backup.zip';

function create_sql_backup($dbname, $usernamedb, $passworddb) {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=$dbname;charset=utf8mb4", $usernamedb, $passworddb, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $backup_file = __DIR__ . '/backups/' . "$dbname.sql";
        $handle = fopen($backup_file, 'w');

        $existing_tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($existing_tables as $table) {
            if ($table) {
                $result = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                fwrite($handle, "\n\n" . $result['Create Table'] . ";\n\n");

                $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $values = array_map(function($value) use ($pdo) {
                        return $value === null ? 'NULL' : $pdo->quote($value);
                    }, array_values($row));
                    fwrite($handle, "INSERT INTO `$table` VALUES (" . implode(',', $values) . ");\n");
                }
            }
        }

        fclose($handle);
        return $backup_file;
    } catch (PDOException $e) {
        error_log("Error creating backup for database $dbname: " . $e->getMessage());
        return false;
    }
}

function create_zip_backup(array $sql_files, $zip_filename) {
    if (!class_exists('ZipArchive')) return false;

    $zip = new ZipArchive();
    if ($zip->open(__DIR__ . '/backups/' . $zip_filename, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        foreach ($sql_files as $sql_file) {
            $zip->addFile($sql_file, basename($sql_file));
        }
        $zip->close();
        return true;
    }
    return false;
}

function create_and_send_backup(array $databases, string $bot_token, string $admin_id, string $zip_filename) {
    $sql_files = [];

    foreach ($databases as $db_info) {
        $dbname = $db_info['dbname'];
        $usernamedb = $db_info['usernamedb'];
        $passworddb = $db_info['passworddb'];

        $backup_file = create_sql_backup($dbname, $usernamedb, $passworddb);
        if ($backup_file && file_exists($backup_file)) {
            $sql_files[] = $backup_file;
        }
    }

    if (!empty($sql_files) && create_zip_backup($sql_files, $zip_filename)) {
        $zip_file = __DIR__ . '/backups/' . $zip_filename;

        list($jy, $jm, $jd) = gregorian_to_jalali(date('Y'), date('m'), date('d'));
        $caption = sprintf('%04d-%02d-%02d %s', $jy, $jm, $jd, date('H:i:s'));

        $url = "https://api.telegram.org/bot$bot_token/sendDocument";
        $post_fields = [
            'chat_id' => $admin_id,
            'document' => new CurlFile(realpath($zip_file)),
            'caption' => $caption,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);

        foreach ($sql_files as $sql_file) {
            @unlink($sql_file);
        }
        @unlink($zip_file);
    }
}

if (isset($uPOST['submit']) && $uPOST['submit'] && empty($ERROR)) {
    $bot_token = $uPOST['bot_token'] ?? '';
    $admin_id = $uPOST['admin_id'] ?? '';
    $dbname = $uPOST['dbname'] ?? '';
    $usernamedb = $uPOST['usernamedb'] ?? '';
    $passworddb = $uPOST['passworddb'] ?? '';
    $zip_filename = $uPOST['zip_filename'] ?? 'backup.zip';

    if ($_SERVER['REQUEST_SCHEME'] != 'https') {
        $ERROR[] = 'برای فعال‌سازی وب‌هوک تلگرام، SSL (https) باید فعال باشد.';
        $ERROR[] = '<i>اگر SSL فعال است، مطمئن شوید آدرس با https باز شده است.</i>';
        $ERROR[] = '<a href="https://' . $_SERVER['HTTP_HOST'] . '/' . $_SERVER['SCRIPT_NAME'] . '">https://' . $_SERVER['HTTP_HOST'] . '/' . $_SERVER['SCRIPT_NAME'] . '</a>';
    }

    if (empty($bot_token) || empty($admin_id) || empty($dbname) || empty($usernamedb) || empty($passworddb) || empty($zip_filename)) {
        $ERROR[] = 'لطفاً تمام فیلدها را پر کنید.';
    }

    if (!isValidTelegramToken($bot_token)) {
        $ERROR[] = "توکن ربات تلگرام معتبر نیست.";
    }

    if (!isValidTelegramId($admin_id)) {
        $ERROR[] = "آیدی عددی ادمین نامعتبر است.";
    }

    $db_list = array_map('trim', explode(',', $dbname));
    $username_list = array_map('trim', explode(',', $usernamedb));
    $password_list = array_map('trim', explode(',', $passworddb));

    if (count($db_list) !== count($username_list) || count($db_list) !== count($password_list)) {
        $ERROR[] = "تعداد دیتابیس‌ها، نام‌های کاربری و رمزهای عبور باید یکسان باشد.";
    }

    $databases = [];
    for ($i = 0; $i < count($db_list); $i++) {
        $databases[] = [
            'dbname' => $db_list[$i],
            'usernamedb' => $username_list[$i],
            'passworddb' => $password_list[$i],
        ];
    }

    if (empty($ERROR)) {
        foreach ($databases as $db_info) {
            try {
                $pdo = new PDO("mysql:host=localhost;dbname={$db_info['dbname']};charset=utf8mb4", $db_info['usernamedb'], $db_info['passworddb'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                $ERROR[] = "❌ عدم اتصال به دیتابیس {$db_info['dbname']}: " . $e->getMessage();
            }
        }
        if (empty($ERROR)) {
            $SUCCESS[] = "✅ اتصال به دیتابیس‌ها موفقیت‌آمیز بود!";
        }
    }

    if (empty($ERROR)) {
        $relative_path = $script_dir . '/index.php';
        $webhook_url = $domain . $relative_path;
        $set_webhook_url = "https://api.telegram.org/bot$bot_token/setWebhook?url=" . urlencode($webhook_url);
        $response = getContents($set_webhook_url);

        if ($response['ok'] !== true) {
            $ERROR[] = 'خطا در تنظیم وب‌هوک: ' . ($response['description'] ?? 'Unknown error');
        } else {
            $success_message = "با موفقیت وب‌هوک ست شد.";
            $telegram_response = getContents("https://api.telegram.org/bot$bot_token/sendMessage?chat_id=$admin_id&text=" . urlencode($success_message));

            create_and_send_backup($databases, $bot_token, $admin_id, $zip_filename);

            $backup_config = [
                'databases' => $databases,
                'bot_token' => $bot_token,
                'admin_id' => $admin_id,
                'domain' => $domain,
                'zip_filename' => $zip_filename,
            ];
            if (@file_put_contents(__DIR__ . '/backup_config.json', json_encode($backup_config, JSON_PRETTY_PRINT)) === false) {
                $ERROR[] = "خطا در ذخیره فایل تنظیمات بکاپ";
            } else {
                $SUCCESS[] = "✅ تنظیمات با موفقیت ذخیره شد و وب‌هوک تنظیم شد.";
                $SUCCESS[] = "دستور کرون‌جاب: <br><code id='cron-command'>$cron_command</code> <button onclick=\"copyCronCommand()\">کپی</button>";

                $index_file = __FILE__;
                if (file_exists($index_file) && !file_exists(__DIR__ . '/setup_complete')) {
                    @unlink($index_file);
                    @touch(__DIR__ . '/setup_complete');
                }
            }
        }
    }
}

if (isset($_GET['run_backup']) || php_sapi_name() === 'cli') {
    $config_file = __DIR__ . '/backup_config.json';
    if (!file_exists($config_file)) exit;

    $backup_config = json_decode(file_get_contents($config_file), true, flags: JSON_THROW_ON_ERROR);
    $databases = $backup_config['databases'];
    $bot_token = $backup_config['bot_token'];
    $admin_id = $backup_config['admin_id'];
    $zip_filename = $backup_config['zip_filename'] ?? 'backup.zip';

    create_and_send_backup($databases, $bot_token, $admin_id, $zip_filename);
    exit;
}
?>

<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سیستم بکاپ خودکار</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <h1>⚙️ تنظیم بکاپ خودکار</h1>

        <?php if (!empty($ERROR)): ?>
            <div class="alert alert-danger">
                <?php echo implode("<br>", $ERROR); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($SUCCESS) && empty($ERROR)): ?>
            <div class="alert alert-success">
                <?php echo implode("<br>", $SUCCESS); ?>
            </div>
        <?php endif; ?>

        <form id="backup-form" <?php if (!empty($SUCCESS) && empty($ERROR)) { echo 'style="display:none;"'; } ?> method="post">
            <div class="form-group">
                <label for="bot_token">توکن بات تلگرام:</label>
                <input type="text" id="bot_token" name="bot_token" placeholder="BOT TOKEN" value="<?php echo $uPOST['bot_token'] ?? ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="admin_id">آیدی عددی ادمین:</label>
                <input type="text" id="admin_id" name="admin_id" placeholder="ADMIN TELEGRAM #ID" value="<?php echo $uPOST['admin_id'] ?? ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="dbname">نام دیتابیس‌ها (با کاما جدا کنید):</label>
                <input type="text" id="dbname" name="dbname" placeholder="db1,db2" value="<?php echo $uPOST['dbname'] ?? ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="usernamedb">نام کاربری دیتابیس‌ها (با کاما جدا کنید):</label>
                <input type="text" id="usernamedb" name="usernamedb" placeholder="user1,user2" value="<?php echo $uPOST['usernamedb'] ?? ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="passworddb">رمز عبور دیتابیس‌ها (با کاما جدا کنید):</label>
                <input type="text" id="passworddb" name="passworddb" placeholder="pass1,pass2" value="<?php echo $uPOST['passworddb'] ?? ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="zip_filename">نام فایل ZIP بکاپ:</label>
                <input type="text" id="zip_filename" name="zip_filename" placeholder="mybackup.zip" value="<?php echo $uPOST['zip_filename'] ?? 'backup.zip'; ?>" required>
            </div>
            <div class="form-group" style="display:none;">
                <label for="domain">دامنه (خودکار):</label>
                <input type="text" id="domain" name="domain" value="<?php echo $domain; ?>" readonly>
            </div>
            <div class="form-group">
                <button type="button" id="get-cron-button" onclick="showCronCommand()">دریافت دامنه خودکار کرون‌جاب</button>
                <div id="cron-display" style="display:none; margin-top: 10px;">
                    <code id="cron-command"><?php echo $cron_command; ?></code>
                    <button onclick="copyCronCommand()">کپی</button>
                </div>
            </div>
            <button type="submit" name="submit" value="submit">ذخیره</button>
        </form>

        <footer>
            <p>Backup System, Made by ♥️ | <a href="https://github.com/mmdv2/backup">Github</a> | <a href="https://t.me/m_m_d_mmd">Telegram</a> | © <?php echo date('Y'); ?></p>
        </footer>
    </div>

    <script>
        function showCronCommand() {
            const cronDisplay = document.getElementById('cron-display');
            cronDisplay.style.display = 'block';
        }

        function copyCronCommand() {
            const cronCommandElement = document.getElementById('cron-command');
            const cronCommand = cronCommandElement.innerText;
            navigator.clipboard.writeText(cronCommand).then(() => {
                alert('دستور کرون‌جاب با موفقیت کپی شد!');
            }).catch(err => {
                alert('خطا در کپی کردن دستور: ' + err);
            });
        }
    </script>
</body>
</html>

<?php
function getContents(string $url): array {
    return json_decode(file_get_contents($url), true);
}

function isValidTelegramToken(string $token): bool {
    return preg_match('/^\d{6,12}:[A-Za-z0-9_-]{35}$/', $token) === 1;
}

function isValidTelegramId(string $id): bool {
    return preg_match('/^\d{6,12}$/', $id) === 1;
}

function sanitizeInput(&$INPUT, array $options = []): mixed {
    $defaultOptions = [
        'allow_html' => false,
        'allowed_tags' => '',
        'remove_spaces' => false,
        'connection' => null,
        'max_length' => 0,
        'encoding' => 'UTF-8'
    ];

    $options = array_merge($defaultOptions, $options);

    if (is_array($INPUT)) {
        return array_map(function($item) use ($options) {
            return sanitizeInput($item, $options);
        }, $INPUT);
    }

    if ($INPUT === null || $INPUT === false) {
        return '';
    }

    $INPUT = (string)$INPUT;

    $INPUT = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $INPUT);

    if ($options['max_length'] > 0) {
        $INPUT = mb_substr($INPUT, 0, $options['max_length'], $options['encoding']);
    }

    if (!$options['allow_html']) {
        $INPUT = strip_tags($INPUT);
    } elseif (!empty($options['allowed_tags'])) {
        $INPUT = strip_tags($INPUT, $options['allowed_tags']);
    }

    if ($options['remove_spaces']) {
        $INPUT = preg_replace('/\s+/', ' ', trim($INPUT));
    }

    $INPUT = htmlspecialchars($INPUT, ENT_QUOTES | ENT_HTML5, $options['encoding']);

    if ($options['connection'] instanceof mysqli) {
        $INPUT = $options['connection']->real_escape_string($INPUT);
    }

    return $INPUT;
}
?>
