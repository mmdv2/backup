<?php
$default_upload_path = __DIR__ . '/backups';
if (!file_exists($default_upload_path)) {
    mkdir($default_upload_path, 0755, true);
}

$config_file = __DIR__ . '/backup_config.json';
if (!file_exists($config_file)) {
    exit;
}

$backup_config = json_decode(file_get_contents($config_file), true, flags: JSON_THROW_ON_ERROR);
$databases = $backup_config['databases'];
$bot_token = $backup_config['bot_token'];
$admin_id = $backup_config['admin_id'];
$zip_filename = $backup_config['zip_filename'] ?? 'backup.zip';

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
    if (!class_exists('ZipArchive')) {
        error_log("ZipArchive library is not installed.");
        return false;
    }

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

function createAndSendBackup(array $databases, string $bot_token, string $admin_id, string $zip_filename) {
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
        $url = "https://api.telegram.org/bot$bot_token/sendDocument";
        $post_fields = [
            'chat_id' => $admin_id,
            'document' => new CurlFile(realpath($zip_file)),
            'caption' => date('Y-m-d H:i:s'),
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

createAndSendBackup($databases, $bot_token, $admin_id, $zip_filename);
?>