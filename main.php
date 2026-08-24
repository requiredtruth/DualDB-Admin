<?php
declare(strict_types=1);

final class DualDBError extends RuntimeException {}

final class DualDB
{
    private array $config;
    /** @var array<string, PDO> */
    private array $connections = [];

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? self::loadConfig();
    }

    public static function loadConfig(): array
    {
        $file = __DIR__ . '/config.php';
        $fromFile = is_file($file) ? require $file : [];
        if (!is_array($fromFile)) {
            throw new DualDBError('config.php must return an array');
        }
        return [
            'sqlite_path' => $fromFile['sqlite_path'] ?? (getenv('DUALDB_SQLITE_PATH') ?: __DIR__ . '/data/dualdb.sqlite'),
            'mysql_dsn' => $fromFile['mysql_dsn'] ?? (getenv('DUALDB_MYSQL_DSN') ?: ''),
            'mysql_user' => $fromFile['mysql_user'] ?? (getenv('DUALDB_MYSQL_USER') ?: ''),
            'mysql_password' => $fromFile['mysql_password'] ?? (getenv('DUALDB_MYSQL_PASSWORD') ?: ''),
        ];
    }

    public function db(string $name): PDO
    {
        if (isset($this->connections[$name])) return $this->connections[$name];
        if (!in_array($name, ['sqlite', 'mysql'], true)) throw new DualDBError('database must be sqlite or mysql');
        if ($name === 'sqlite') {
            $path = (string)$this->config['sqlite_path'];
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new DualDBError('unable to create SQLite data directory');
            $pdo = new PDO('sqlite:' . $path);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            if (!$this->config['mysql_dsn']) throw new DualDBError('MySQL is not configured');
            $pdo = new PDO((string)$this->config['mysql_dsn'], (string)$this->config['mysql_user'], (string)$this->config['mysql_password']);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $this->connections[$name] = $pdo;
    }

    public function quoteIdentifier(string $db, string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $identifier)) throw new DualDBError("unsafe identifier: {$identifier}");
        return $db === 'mysql' ? '`' . $identifier . '`' : '"' . $identifier . '"';
    }

    public function tables(string $db): array
    {
        $pdo = $this->db($db);
        $sql = $db === 'mysql' ? 'SHOW TABLES' : "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
        return array_map(static fn(array $row): string => (string)array_values($row)[0], $pdo->query($sql)->fetchAll());
    }

    public function columns(string $db, string $table): array
    {
        $name = $this->quoteIdentifier($db, $table);
        if ($db === 'mysql') {
            return array_map(static fn(array $row): array => ['name' => $row['Field'], 'type' => $row['Type'], 'pk' => $row['Key'] === 'PRI'], $this->db($db)->query("DESCRIBE {$name}")->fetchAll());
        }
        return array_map(static fn(array $row): array => ['name' => $row['name'], 'type' => $row['type'], 'pk' => (bool)$row['pk']], $this->db($db)->query("PRAGMA table_info({$name})")->fetchAll());
    }

    public function rows(string $db, string $table, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit)); $offset = max(0, $offset);
        $tableName = $this->quoteIdentifier($db, $table);
        return $this->db($db)->query("SELECT * FROM {$tableName} LIMIT {$limit} OFFSET {$offset}")->fetchAll();
    }

    public function setupDemo(): void
    {
        $pdo = $this->db('sqlite');
        $pdo->exec('CREATE TABLE IF NOT EXISTS demo_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, category TEXT NOT NULL, quantity INTEGER NOT NULL DEFAULT 0)');
        if ((int)$pdo->query('SELECT COUNT(*) FROM demo_items')->fetchColumn() === 0) {
            $statement = $pdo->prepare('INSERT INTO demo_items(name, category, quantity) VALUES(?, ?, ?)');
            foreach ([['Notebook', 'office', 12], ['Adapter', 'hardware', 4], ['Tea', 'kitchen', 20]] as $row) $statement->execute($row);
        }
    }

    public function createRow(string $db, string $table, array $values): void
    {
        if (!$values) throw new DualDBError('at least one column value is required');
        $allowed = array_column($this->columns($db, $table), 'name');
        foreach (array_keys($values) as $key) if (!in_array($key, $allowed, true)) throw new DualDBError("unknown column: {$key}");
        $columns = array_map(fn(string $value): string => $this->quoteIdentifier($db, $value), array_keys($values));
        $sql = 'INSERT INTO ' . $this->quoteIdentifier($db, $table) . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')';
        $this->db($db)->prepare($sql)->execute(array_values($values));
    }

    public function updateRow(string $db, string $table, string $pk, mixed $pkValue, array $values): void
    {
        if (!$values) throw new DualDBError('at least one changed value is required');
        $allowed = array_column($this->columns($db, $table), 'name');
        if (!in_array($pk, $allowed, true)) throw new DualDBError('primary key column is invalid');
        foreach (array_keys($values) as $key) if (!in_array($key, $allowed, true)) throw new DualDBError("unknown column: {$key}");
        $sets = array_map(fn(string $key): string => $this->quoteIdentifier($db, $key) . ' = ?', array_keys($values));
        $sql = 'UPDATE ' . $this->quoteIdentifier($db, $table) . ' SET ' . implode(',', $sets) . ' WHERE ' . $this->quoteIdentifier($db, $pk) . ' = ?';
        $this->db($db)->prepare($sql)->execute([...array_values($values), $pkValue]);
    }

    public function deleteRow(string $db, string $table, string $pk, mixed $pkValue): void
    {
        $allowed = array_column($this->columns($db, $table), 'name');
        if (!in_array($pk, $allowed, true)) throw new DualDBError('primary key column is invalid');
        $sql = 'DELETE FROM ' . $this->quoteIdentifier($db, $table) . ' WHERE ' . $this->quoteIdentifier($db, $pk) . ' = ?';
        $this->db($db)->prepare($sql)->execute([$pkValue]);
    }

    public function dropTable(string $db, string $table): void
    {
        $this->db($db)->exec('DROP TABLE ' . $this->quoteIdentifier($db, $table));
    }

    public function executeSql(string $db, string $sql): array
    {
        if (!trim($sql)) throw new DualDBError('SQL is empty');
        $statement = $this->db($db)->prepare($sql); $statement->execute();
        return $statement->columnCount() ? $statement->fetchAll() : [['affected_rows' => $statement->rowCount()]];
    }

    public function importFile(string $db, string $file, ?string $table = null, string $prefix = ''): array
    {
        if (!is_file($file) || !is_readable($file)) throw new DualDBError('import file is not readable');
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $base = preg_replace('/[^A-Za-z0-9_]+/', '_', pathinfo($file, PATHINFO_FILENAME));
        if ($table === null || trim($table) === '') {
            $candidate = $prefix . $base;
            $existing = $this->tables($db);
            $suffix = 2; $table = $candidate;
            while (in_array($table, $existing, true)) $table = $candidate . '_' . $suffix++;
        }
        $this->quoteIdentifier($db, $table);
        if ($extension === 'csv') $rows = $this->readCsv($file);
        elseif ($extension === 'json') {
            $decoded = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $rows = array_is_list($decoded) ? $decoded : [$decoded];
        } else throw new DualDBError('import supports only CSV and JSON');
        if (!$rows || !is_array($rows[0])) throw new DualDBError('import contains no object rows');
        $columns = array_values(array_unique(array_merge(...array_map('array_keys', $rows))));
        foreach ($columns as $column) $this->quoteIdentifier($db, (string)$column);
        $definitions = array_map(fn(string $column): string => $this->quoteIdentifier($db, $column) . ' TEXT', $columns);
        $this->db($db)->exec('CREATE TABLE IF NOT EXISTS ' . $this->quoteIdentifier($db, $table) . ' (' . implode(',', $definitions) . ')');
        $insert = $this->db($db)->prepare('INSERT INTO ' . $this->quoteIdentifier($db, $table) . ' (' . implode(',', array_map(fn(string $column): string => $this->quoteIdentifier($db, $column), $columns)) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
        $this->db($db)->beginTransaction();
        try {
            foreach ($rows as $row) $insert->execute(array_map(static fn(string $column): mixed => $row[$column] ?? null, $columns));
            $this->db($db)->commit();
        } catch (Throwable $error) { $this->db($db)->rollBack(); throw $error; }
        return ['table' => $table, 'rows' => count($rows), 'columns' => count($columns)];
    }

    private function readCsv(string $file): array
    {
        $handle = fopen($file, 'rb'); if (!$handle) throw new DualDBError('unable to open CSV');
        $header = fgetcsv($handle, null, ',', '"', '\\');
        if (!$header || count(array_filter($header, 'strlen')) !== count($header)) { fclose($handle); throw new DualDBError('CSV header is empty or invalid'); }
        $rows = [];
        while (($values = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            if (count($values) !== count($header)) { fclose($handle); throw new DualDBError('CSV row width does not match header'); }
            $rows[] = array_combine($header, $values);
        }
        fclose($handle); return $rows;
    }

    public function transfer(string $source, string $destination, string $table): array
    {
        if ($source === $destination) throw new DualDBError('source and destination must differ');
        $columns = $this->columns($source, $table); if (!$columns) throw new DualDBError('source table has no columns');
        $definitions = array_map(fn(array $column): string => $this->quoteIdentifier($destination, $column['name']) . ' TEXT', $columns);
        $target = $this->quoteIdentifier($destination, $table);
        $this->db($destination)->exec("DROP TABLE IF EXISTS {$target}");
        $this->db($destination)->exec("CREATE TABLE {$target} (" . implode(',', $definitions) . ')');
        $names = array_column($columns, 'name');
        $insert = $this->db($destination)->prepare("INSERT INTO {$target} (" . implode(',', array_map(fn(string $name): string => $this->quoteIdentifier($destination, $name), $names)) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')');
        $count = 0; $offset = 0; $this->db($destination)->beginTransaction();
        try {
            do {
                $batch = $this->rows($source, $table, 500, $offset);
                foreach ($batch as $row) { $insert->execute(array_map(static fn(string $name): mixed => $row[$name], $names)); $count++; }
                $offset += count($batch);
            } while (count($batch) === 500);
            $this->db($destination)->commit();
        } catch (Throwable $error) { $this->db($destination)->rollBack(); throw $error; }
        return ['table' => $table, 'rows' => $count, 'source' => $source, 'destination' => $destination];
    }

    public function export(string $db, string $table, string $format): string
    {
        $rows = []; $offset = 0;
        do { $batch = $this->rows($db, $table, 500, $offset); array_push($rows, ...$batch); $offset += count($batch); }
        while (count($batch) === 500);
        if ($format === 'json') return json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if ($format !== 'csv') throw new DualDBError('export format must be csv or json');
        $handle = fopen('php://temp', 'w+');
        if ($rows) { fputcsv($handle, array_keys($rows[0]), ',', '"', '\\'); foreach ($rows as $row) fputcsv($handle, $row, ',', '"', '\\'); }
        rewind($handle); $output = stream_get_contents($handle); fclose($handle); return (string)$output;
    }
}

function cli(DualDB $app, array $argv): int
{
    $command = $argv[1] ?? 'help';
    try {
        $result = match ($command) {
            'help' => ['commands' => ['setup-demo', 'status', 'tables DB', 'schema DB TABLE', 'rows DB TABLE [LIMIT]', 'import DB FILE [TABLE] [PREFIX]', 'transfer SOURCE DESTINATION TABLE', 'sql DB QUERY', 'export DB TABLE csv|json']],
            'setup-demo' => (function () use ($app): array { $app->setupDemo(); return ['created' => 'demo_items']; })(),
            'status' => ['sqlite' => $app->tables('sqlite'), 'mysql' => (function () use ($app): array { try { return $app->tables('mysql'); } catch (Throwable $e) { return ['unavailable' => $e->getMessage()]; } })()],
            'tables' => $app->tables($argv[2] ?? 'sqlite'),
            'schema' => $app->columns($argv[2] ?? 'sqlite', $argv[3] ?? throw new DualDBError('table required')),
            'rows' => $app->rows($argv[2] ?? 'sqlite', $argv[3] ?? throw new DualDBError('table required'), (int)($argv[4] ?? 50)),
            'import' => $app->importFile($argv[2] ?? 'sqlite', $argv[3] ?? throw new DualDBError('file required'), $argv[4] ?? null, $argv[5] ?? ''),
            'transfer' => $app->transfer($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? throw new DualDBError('table required')),
            'sql' => $app->executeSql($argv[2] ?? 'sqlite', $argv[3] ?? throw new DualDBError('query required')),
            'export' => ['content' => $app->export($argv[2] ?? 'sqlite', $argv[3] ?? throw new DualDBError('table required'), $argv[4] ?? 'json')],
            default => throw new DualDBError("unknown command: {$command}"),
        };
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; return 0;
    } catch (Throwable $error) { fwrite(STDERR, "error: {$error->getMessage()}\n"); return 2; }
}

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function web(DualDB $app): void
{
    session_start(); $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    $_SESSION['groups'] ??= ['sqlite' => [], 'mysql' => []];
    $screen = $_GET['screen'] ?? 'dashboard'; $db = in_array($_GET['db'] ?? 'sqlite', ['sqlite', 'mysql'], true) ? $_GET['db'] : 'sqlite';
    $message = ''; $error = '';
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) throw new DualDBError('CSRF validation failed');
            $action = $_POST['action'] ?? '';
            if ($action === 'sql') { $_SESSION['result'] = $app->executeSql($db, (string)$_POST['sql']); $message = 'SQL executed.'; }
            elseif ($action === 'create') { $app->createRow($db, (string)$_POST['table'], is_array($_POST['values'] ?? null) ? $_POST['values'] : []); $message = 'Row added.'; }
            elseif ($action === 'update') { $app->updateRow($db, (string)$_POST['table'], (string)$_POST['pk'], $_POST['pk_value'], is_array($_POST['values'] ?? null) ? $_POST['values'] : []); $message = 'Row updated.'; }
            elseif ($action === 'delete') { $app->deleteRow($db, (string)$_POST['table'], (string)$_POST['pk'], $_POST['pk_value']); $message = 'Row deleted.'; }
            elseif ($action === 'drop') { $app->dropTable($db, (string)$_POST['table']); $message = 'Table dropped.'; }
            elseif ($action === 'transfer') { $_SESSION['result'] = $app->transfer((string)$_POST['source'], (string)$_POST['destination'], (string)$_POST['table']); $message = 'Transfer completed.'; }
            elseif ($action === 'group_save') {
                $name = trim((string)($_POST['group_name'] ?? ''));
                if (!preg_match('/^[A-Za-z][A-Za-z0-9 _-]{0,47}$/', $name)) throw new DualDBError('group name is invalid');
                $available = $app->tables($db);
                $selected = array_values(array_intersect($available, is_array($_POST['tables'] ?? null) ? $_POST['tables'] : []));
                if (!$selected) throw new DualDBError('select at least one current table');
                $_SESSION['groups'][$db][$name] = $selected; ksort($_SESSION['groups'][$db]); $message = 'Table group saved.';
            }
            elseif ($action === 'group_delete') { unset($_SESSION['groups'][$db][(string)$_POST['group_name']]); $message = 'Table group removed.'; }
            elseif ($action === 'import') {
                $upload = $_FILES['file'] ?? null; if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) throw new DualDBError('valid upload required');
                $extension = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
                $staged = $upload['tmp_name'] . '.' . $extension;
                if (!copy($upload['tmp_name'], $staged)) throw new DualDBError('unable to stage uploaded file');
                try { $_SESSION['result'] = $app->importFile($db, $staged, $_POST['table'] ?: null, (string)$_POST['prefix']); }
                finally { @unlink($staged); }
                $message = 'Import completed.';
            } else throw new DualDBError('unsupported action');
        }
    } catch (Throwable $caught) { $error = $caught->getMessage(); }
    $tables = []; try { $tables = $app->tables($db); } catch (Throwable $caught) { if ($error === '') $error = $caught->getMessage(); }
    foreach ($_SESSION['groups'][$db] as $name => $members) {
        $_SESSION['groups'][$db][$name] = array_values(array_intersect($tables, $members));
        if (!$_SESSION['groups'][$db][$name]) unset($_SESSION['groups'][$db][$name]);
    }
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>DualDB Admin</title><style>
    :root{font:15px/1.45 system-ui;color:#eaf2f7;background:#091018}*{box-sizing:border-box}body{margin:0;display:grid;grid-template-columns:250px 1fr;min-height:100vh}aside{padding:16px;background:#101c28;border-right:1px solid #294050}main{padding:20px;overflow:auto}a,button{color:#8edbff}a{display:block;padding:7px;text-decoration:none}details{margin:8px 0}summary{cursor:pointer;font-weight:700}table{border-collapse:collapse;width:100%;background:#101c28}th,td{padding:8px;border:1px solid #294050;text-align:left}input,select,textarea,button{font:inherit;color:#eaf2f7;background:#122334;border:1px solid #37566d;border-radius:5px;padding:8px}textarea{width:100%;min-height:150px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.card{padding:14px;background:#101c28;border:1px solid #294050;border-radius:9px}.ok{color:#8df0af}.bad{color:#ff9d9d}.danger{color:#ffabab}@media(max-width:700px){body{grid-template-columns:1fr}aside{position:static;border-right:0}main{padding:12px}}
    </style></head><body><aside><h1>DualDB Admin</h1><label>Database <select onchange="location='?screen=<?=h($screen)?>&db='+this.value"><option value="sqlite"<?=$db==='sqlite'?' selected':''?>>SQLite</option><option value="mysql"<?=$db==='mysql'?' selected':''?>>MySQL</option></select></label><nav><a href="?screen=dashboard&db=<?=h($db)?>">Dashboard</a><a href="?screen=import&db=<?=h($db)?>">Upload CSV/JSON</a><a href="?screen=transfer&db=<?=h($db)?>">Transfer</a><a href="?screen=sql&db=<?=h($db)?>">SQL</a><a href="?screen=groups&db=<?=h($db)?>">Manage table groups</a><a href="?screen=help&db=<?=h($db)?>">CLI Help</a></nav><details><summary>TABLES (<?=count($tables)?>)</summary><?php foreach($tables as $table):?><a href="?screen=browse&db=<?=h($db)?>&table=<?=urlencode($table)?>"><?=h($table)?></a><?php endforeach?></details><details><summary>TABLE GROUPS (<?=count($_SESSION['groups'][$db])?>)</summary><?php foreach($_SESSION['groups'][$db] as $group=>$members):?><details><summary><?=h($group)?></summary><?php foreach($members as $table):?><a href="?screen=browse&db=<?=h($db)?>&table=<?=urlencode($table)?>"><?=h($table)?></a><?php endforeach?></details><?php endforeach?></details></aside><main><?php if($message):?><p class="ok"><?=h($message)?></p><?php endif;if($error):?><p class="bad">error: <?=h($error)?></p><?php endif?>
    <?php if($screen==='dashboard'):?><h2>Dashboard</h2><div class="grid"><section class="card"><b>Active database</b><p><?=h($db)?></p></section><section class="card"><b>Tables</b><p><?=count($tables)?></p></section><section class="card"><b>Mode</b><p>PDO / parameterized mutations</p></section></div>
    <?php elseif($screen==='browse'):$table=(string)($_GET['table']??'');?><h2>Browse <?=h($table)?></h2><?php try{$rows=$app->rows($db,$table);$columns=$app->columns($db,$table);$pk=null;foreach($columns as $column){if($column['pk']){$pk=$column['name'];break;}}?><p><?=count($rows)?> rows shown (maximum 50)</p><table><thead><tr><?php foreach($columns as $column):?><th><?=h($column['name'])?><small> <?=h($column['type'])?></small></th><?php endforeach?><?php if($pk):?><th>Actions</th><?php endif?></tr></thead><tbody><?php foreach($rows as $row):?><tr><?php foreach($columns as $column):?><td><?=h($row[$column['name']]??'')?></td><?php endforeach?><?php if($pk):?><td><a href="?screen=browse&db=<?=h($db)?>&table=<?=urlencode($table)?>&edit=<?=urlencode((string)$row[$pk])?>">Edit</a><form method="post" onsubmit="return confirm('Delete this row?')"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="table" value="<?=h($table)?>"><input type="hidden" name="pk" value="<?=h($pk)?>"><input type="hidden" name="pk_value" value="<?=h($row[$pk])?>"><button class="danger">Delete</button></form></td><?php endif?></tr><?php endforeach?></tbody></table><section class="card"><h3>Add row</h3><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="create"><input type="hidden" name="table" value="<?=h($table)?>"><?php foreach($columns as $column):if($column['pk'])continue;?><label><?=h($column['name'])?> <input name="values[<?=h($column['name'])?>]"></label><?php endforeach?><button>Add</button></form></section><?php if($pk&&isset($_GET['edit'])):$editing=null;foreach($rows as $row){if((string)$row[$pk]===(string)$_GET['edit']){$editing=$row;break;}}if($editing):?><section class="card"><h3>Edit row <?=h($_GET['edit'])?></h3><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="update"><input type="hidden" name="table" value="<?=h($table)?>"><input type="hidden" name="pk" value="<?=h($pk)?>"><input type="hidden" name="pk_value" value="<?=h($editing[$pk])?>"><?php foreach($columns as $column):if($column['name']===$pk)continue;?><label><?=h($column['name'])?> <input name="values[<?=h($column['name'])?>]" value="<?=h($editing[$column['name']]??'')?>"></label><?php endforeach?><button>Save changes</button></form></section><?php endif;endif?><form method="post" onsubmit="return confirm('Drop this table?')"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="drop"><input type="hidden" name="table" value="<?=h($table)?>"><button class="danger">Drop table</button></form><?php }catch(Throwable $caught){?><p class="bad">error: <?=h($caught->getMessage())?></p><?php }?>
    <?php elseif($screen==='import'):?><h2>Upload CSV or JSON</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="import"><label>File <input type="file" name="file" accept=".csv,.json" required></label><label>Explicit table (optional) <input name="table"></label><label>Prefix <input name="prefix" placeholder="import_"></label><button>Import into <?=h($db)?></button></form>
    <?php elseif($screen==='transfer'):?><h2>Transfer table</h2><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="transfer"><label>Source <select name="source"><option>sqlite</option><option>mysql</option></select></label><label>Destination <select name="destination"><option>mysql</option><option>sqlite</option></select></label><label>Table <input name="table" required></label><button>Replace destination table</button></form>
    <?php elseif($screen==='groups'):?><h2>Table groups</h2><p>Groups are navigation-only and begin collapsed in the sidebar.</p><form method="post" class="card"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="group_save"><label>Group name <input name="group_name" required></label><?php foreach($tables as $table):?><label><input type="checkbox" name="tables[]" value="<?=h($table)?>"> <?=h($table)?></label><?php endforeach?><button>Save selected tables</button></form><?php foreach($_SESSION['groups'][$db] as $group=>$members):?><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="group_delete"><input type="hidden" name="group_name" value="<?=h($group)?>"><b><?=h($group)?></b> — <?=h(implode(', ',$members))?> <button class="danger">Remove group</button></form><?php endforeach?>
    <?php elseif($screen==='sql'):?><h2>SQL runner</h2><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="sql"><textarea name="sql" spellcheck="false" required></textarea><button>Run on <?=h($db)?></button></form><?php if(isset($_SESSION['result'])):?><pre><?=h(json_encode($_SESSION['result'],JSON_PRETTY_PRINT))?></pre><?php unset($_SESSION['result']);endif?>
    <?php else:?><h2>CLI Help</h2><pre>php -f main.php help
php -f main.php setup-demo
php -f main.php status
php -f main.php tables sqlite
php -f main.php schema sqlite demo_items
php -f main.php rows sqlite demo_items 50
php -f main.php import sqlite data.csv optional_table import_
php -f main.php transfer sqlite mysql demo_items
php -f main.php sql sqlite "SELECT * FROM demo_items"
php -f main.php export sqlite demo_items json</pre><?php endif?></main></body></html><?php
}

if (!defined('DUALDB_TEST')) {
    $application = new DualDB();
    if (PHP_SAPI === 'cli') exit(cli($application, $argv));
    web($application);
}
