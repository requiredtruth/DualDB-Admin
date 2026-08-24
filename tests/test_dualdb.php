<?php
declare(strict_types=1);
define('DUALDB_TEST', true);
require dirname(__DIR__) . '/main.php';

$directory = sys_get_temp_dir() . '/dualdb-test-' . bin2hex(random_bytes(5));
mkdir($directory, 0700, true);
$app = new DualDB(['sqlite_path' => $directory . '/test.sqlite', 'mysql_dsn' => '', 'mysql_user' => '', 'mysql_password' => '']);

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$app->setupDemo();
check($app->tables('sqlite') === ['demo_items'], 'demo table missing');
check(count($app->rows('sqlite', 'demo_items')) === 3, 'demo row count');
$app->createRow('sqlite', 'demo_items', ['name' => 'Cable', 'category' => 'hardware', 'quantity' => 2]);
check(count($app->rows('sqlite', 'demo_items')) === 4, 'row insert failed');
$app->updateRow('sqlite', 'demo_items', 'id', 4, ['quantity' => 7]);
check((int)$app->rows('sqlite', 'demo_items')[3]['quantity'] === 7, 'row update failed');
$app->deleteRow('sqlite', 'demo_items', 'id', 4);
check(count($app->rows('sqlite', 'demo_items')) === 3, 'row delete failed');

$csv = $directory . '/fixture.csv'; file_put_contents($csv, "name,value\nalpha,1\nbeta,2\n");
$result = $app->importFile('sqlite', $csv, null, 'import_');
check($result['table'] === 'import_fixture' && $result['rows'] === 2, 'CSV prefix import failed');
$numbered = $app->importFile('sqlite', $csv, null, 'import_');
check($numbered['table'] === 'import_fixture_2', 'automatic collision numbering failed');
$json = $directory . '/objects.json'; file_put_contents($json, json_encode([['name' => 'gamma', 'value' => 3]], JSON_THROW_ON_ERROR));
check($app->importFile('sqlite', $json, 'json_items')['rows'] === 1, 'JSON import failed');
check(str_contains($app->export('sqlite', 'json_items', 'json'), 'gamma'), 'JSON export failed');
check(str_starts_with($app->export('sqlite', 'import_fixture', 'csv'), 'name,value'), 'CSV export failed');

try { $app->rows('sqlite', 'demo_items; DROP TABLE demo_items'); check(false, 'unsafe identifier accepted'); }
catch (DualDBError $expected) { check(str_contains($expected->getMessage(), 'unsafe identifier'), 'wrong identifier error'); }

unlink($csv); unlink($json); unlink($directory . '/test.sqlite'); rmdir($directory);
echo "DualDB PHP tests passed\n";
