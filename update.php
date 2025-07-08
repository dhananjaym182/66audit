<?php
const ALTUMCODE = 66;
define('ROOT', realpath(__DIR__ . '/..') . '/');
define('DEBUG', 0);
define('CACHE', 1);
define('LOGGING', 1);
require_once ROOT . 'app/init.php';
require_once ROOT . 'update/info.php';

mysqli_report(MYSQLI_REPORT_OFF);

// DB connection
$database = new \mysqli(
    DATABASE_SERVER,
    DATABASE_USERNAME,
    DATABASE_PASSWORD,
    DATABASE_NAME
);

if($database->connect_error) {
    die(json_encode([
        'status' => 'error',
        'message' => 'The database connection has failed!'
    ]));
}
$database->set_charset('utf8mb4');

// Get current product_info + license (fallback safe)
$product_info = $database->query("SELECT `value` FROM `settings` WHERE `key` = 'product_info'")?->fetch_object();
$product_info = $product_info ? json_decode($product_info->value) : null;

$license = $database->query("SELECT `value` FROM `settings` WHERE `key` = 'license'")?->fetch_object();
$license = $license ? json_decode($license->value) : (object)['type' => 'standard'];

$current_code = $product_info->code ?? (defined('PRODUCT_CODE') ? PRODUCT_CODE : '0000');

// Get list of updates from info.php
$update_key = array_search($current_code, $updates);
$update_key = ($update_key !== false) ? $update_key + 1 : 0;
$updates_to_run = array_slice($updates, $update_key);

// Paths
$updates_dir = ROOT . 'update/sql/';
$changelog_file = ROOT . 'update/CHANGELOG.txt';
if (!is_dir($updates_dir)) mkdir($updates_dir, 0755, true);

// Loop through update versions
foreach($updates_to_run as $version_code) {
    $sql_file = $updates_dir . $version_code . '.sql';

    // Auto-generate SQL file if missing
    if (!file_exists($sql_file)) {
        $sql_template = <<<SQL
-- Standard SQL queries
UPDATE `settings` SET `value` = '{$version_code}' WHERE `key` = 'version';
-- SEPARATOR --

-- EXTENDED SEPARATOR --

-- Extended license SQL queries
-- SEPARATOR --
SQL;
        file_put_contents($sql_file, $sql_template);

        $timestamp = date('Y-m-d H:i');
        $changelog_entry = "[{$version_code}] SQL update file created on {$timestamp}\n";
        file_put_contents($changelog_file, $changelog_entry, FILE_APPEND);

        // Skip execution for new files to let user fill them
        continue;
    }

    // Read and process the SQL file
    $dump_content = file_get_contents($sql_file);
    $exploded = explode('-- EXTENDED SEPARATOR --', $dump_content);

    // Run standard queries
    $standard_queries = explode('-- SEPARATOR --', $exploded[0]);

    foreach($standard_queries as $query) {
        $query = trim($query);
        if(empty($query)) continue;

        $database->query($query);
        if($database->error) {
            die(json_encode([
                'status' => 'error',
                'message' => "Standard SQL error: " . $database->error
            ]));
        }
    }

    // Run extended queries if license allows
    if(isset($exploded[1]) && in_array(strtolower($license->type), ['extended', 'extended license', 'special'])) {
        $extended_queries = explode('-- SEPARATOR --', $exploded[1]);

        foreach($extended_queries as $query) {
            $query = trim($query);
            if(empty($query)) continue;

            $database->query($query);
            if($database->error) {
                die(json_encode([
                    'status' => 'error',
                    'message' => "Extended SQL error: " . $database->error
                ]));
            }
        }
    }
}

// Clear cache
foreach(glob(ROOT . 'app/languages/cache/*.php') as $file_path) {
    unlink($file_path);
}
\Altum\Cache::initialize();
cache()->clear();

// Done
die(json_encode([
    'status' => 'success',
    'message' => 'Update completed successfully.'
]));
