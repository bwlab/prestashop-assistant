<?php
/**
 * Merchant Assistant - script eseguito SUL SERVER del cliente.
 * Viene inviato via stdin: ssh host php -- <comando> <payload-base64-json>
 * Nessun file installato sul server. Compatibile PHP 7.1+ (PrestaShop 1.7.x / 8.x / 9.x).
 * Uscita: 0 ok, 3 errore gestito (messaggio su stderr con prefisso "ERRORE:").
 */

class MaError extends Exception
{
}

const MA_READ_FIRST = array('SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN');
const MA_WRITE_FIRST = array('UPDATE', 'DELETE', 'INSERT', 'REPLACE');
const MA_ALWAYS_DENIED = '/\b(INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD_FILE|SLEEP|BENCHMARK|GET_LOCK)\b/';
const MA_WRITE_DENIED = '/\b(DROP|TRUNCATE|ALTER|GRANT|REVOKE|CREATE|RENAME|LOAD|HANDLER|LOCK|UNLOCK|SHUTDOWN|KILL|SET\s+GLOBAL|SET\s+SESSION)\b/';

/* ============================== logica pura ============================== */

/**
 * Restituisce la query con commenti -> spazi e contenuto delle stringhe -> 'x',
 * mantenendo la stessa lunghezza: le posizioni restano allineate con l'originale.
 */
function ma_mask($sql)
{
    $out = '';
    $n = strlen($sql);
    $i = 0;
    while ($i < $n) {
        $c = $sql[$i];
        $next = $i + 1 < $n ? $sql[$i + 1] : '';
        if ($c === "'" || $c === '"') {
            $out .= $c;
            $i++;
            $closed = false;
            while ($i < $n) {
                if ($sql[$i] === '\\' && $i + 1 < $n) {
                    $out .= 'xx';
                    $i += 2;
                    continue;
                }
                if ($sql[$i] === $c) {
                    if ($i + 1 < $n && $sql[$i + 1] === $c) { // quote raddoppiato
                        $out .= 'xx';
                        $i += 2;
                        continue;
                    }
                    $out .= $c;
                    $i++;
                    $closed = true;
                    break;
                }
                $out .= 'x';
                $i++;
            }
            if (!$closed) {
                throw new MaError('Stringa non chiusa nella query.');
            }
            continue;
        }
        if (($c === '-' && $next === '-' && ($i + 2 >= $n || ctype_space($sql[$i + 2]))) || $c === '#') {
            while ($i < $n && $sql[$i] !== "\n") {
                $out .= ' ';
                $i++;
            }
            continue;
        }
        if ($c === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) {
                throw new MaError('Commento non chiuso nella query.');
            }
            $out .= str_repeat(' ', $end + 2 - $i);
            $i = $end + 2;
            continue;
        }
        $out .= $c;
        $i++;
    }
    return $out;
}

/** Toglie ; e spazi finali. Lavora su originale e maschera insieme per tenerli allineati. */
function ma_trim_end($sql, $masked)
{
    $len = strlen($masked);
    while ($len > 0 && (ctype_space($masked[$len - 1]) || $masked[$len - 1] === ';')) {
        $len--;
    }
    return array(substr($sql, 0, $len), substr($masked, 0, $len));
}

/**
 * Valida una query. $mode = 'read' | 'write'. Lancia MaError se non ammessa.
 * Restituisce array(sql ripulita, maschera, prima parola).
 */
function ma_guard($sql, $mode)
{
    $sql = trim($sql);
    list($sql, $masked) = ma_trim_end($sql, ma_mask($sql));
    $masked = ltrim($masked);
    $sql = substr($sql, strlen($sql) - strlen($masked));
    if ($masked === '') {
        throw new MaError('Query vuota.');
    }
    if (strpos($masked, ';') !== false) {
        throw new MaError('Una sola istruzione alla volta (trovato ";" in mezzo alla query).');
    }
    $upper = strtoupper($masked);
    $first = strtok(ltrim($upper), " \t\r\n(");
    if (preg_match(MA_ALWAYS_DENIED, $upper, $m)) {
        throw new MaError('Istruzione non ammessa: ' . $m[1]);
    }
    if ($mode === 'read') {
        if (!in_array($first, MA_READ_FIRST, true)) {
            throw new MaError("In lettura sono ammesse solo SELECT/SHOW/DESCRIBE/EXPLAIN (trovato $first). Per modificare usa 'ma write'.");
        }
        return array($sql, $masked, $first);
    }
    if (!in_array($first, MA_WRITE_FIRST, true)) {
        throw new MaError("In scrittura sono ammesse solo UPDATE/DELETE/INSERT/REPLACE (trovato $first).");
    }
    if (preg_match(MA_WRITE_DENIED, $upper, $m)) {
        throw new MaError('Istruzione non ammessa: ' . $m[1] . '. Per modifiche di struttura apri un ticket a bwlab.');
    }
    if (($first === 'UPDATE' || $first === 'DELETE') && !preg_match('/\bWHERE\b/', $upper)) {
        throw new MaError("$first senza WHERE non ammessa: modificherebbe tutta la tabella.");
    }
    ma_parse_write_masked($sql, $masked); // verifica forma a tabella singola
    return array($sql, $masked, $first);
}

/** Estrae tipo, tabella e WHERE (testo originale) da una scrittura a tabella singola. */
function ma_parse_write_masked($sql, $masked)
{
    $id = '`?([A-Za-z0-9_]+)`?';
    if (preg_match('/^\s*UPDATE\s+(?:LOW_PRIORITY\s+)?(?:IGNORE\s+)?' . $id . '\s+SET\b/i', $masked, $m)) {
        $type = 'UPDATE';
    } elseif (preg_match('/^\s*DELETE\s+(?:LOW_PRIORITY\s+)?(?:QUICK\s+)?(?:IGNORE\s+)?FROM\s+' . $id . '\s+WHERE\b/i', $masked, $m)) {
        $type = 'DELETE';
    } elseif (preg_match('/^\s*(INSERT|REPLACE)\s+(?:LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+)?(?:IGNORE\s+)?INTO\s+' . $id . '/i', $masked, $m)) {
        return array('type' => strtoupper($m[1]), 'table' => $m[2], 'where' => null);
    } else {
        throw new MaError('Una tabella alla volta: niente JOIN, alias o più tabelle nella stessa modifica.');
    }
    if (!preg_match('/\bWHERE\b/i', $masked, $w, PREG_OFFSET_CAPTURE)) {
        throw new MaError("$type senza WHERE non ammessa.");
    }
    $start = $w[0][1] + 5;
    $end = strlen($masked);
    if (preg_match('/\b(ORDER\s+BY|LIMIT)\b/i', $masked, $t, PREG_OFFSET_CAPTURE, $start)) {
        $end = $t[0][1];
    }
    return array('type' => $type, 'table' => $m[1], 'where' => trim(substr($sql, $start, $end - $start)));
}

function ma_parse_write($sql)
{
    list($sql, $masked) = ma_guard($sql, 'write');
    return ma_parse_write_masked($sql, $masked);
}

/** PREFIX_ come negli script SQL di PrestaShop: lo stesso SQL vale su stage e prod. */
function ma_prefix($sql, $prefix)
{
    return str_replace('PREFIX_', $prefix, $sql);
}

function ma_quote($v)
{
    if ($v === null) {
        return 'NULL';
    }
    return "'" . strtr((string) $v, array('\\' => '\\\\', "'" => "\\'", "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "\x1a" => '\\Z')) . "'";
}

/** SQL che rimette le righe com'erano prima della modifica. */
function ma_rollback_sql($table, array $rows)
{
    $out = '';
    foreach ($rows as $row) {
        $cols = array();
        $vals = array();
        $upd = array();
        foreach ($row as $c => $v) {
            $cols[] = "`$c`";
            $vals[] = ma_quote($v);
            $upd[] = "`$c`=VALUES(`$c`)";
        }
        $out .= "INSERT INTO `$table` (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $upd) . ";\n";
    }
    return $out;
}

function ma_tsv(array $rows)
{
    if (!$rows) {
        return "(nessuna riga)\n";
    }
    $esc = function ($v) {
        return $v === null ? 'NULL' : strtr((string) $v, array("\t" => '\t', "\n" => '\n', "\r" => '\r'));
    };
    $out = implode("\t", array_keys($rows[0])) . "\n";
    foreach ($rows as $r) {
        $out .= implode("\t", array_map($esc, $r)) . "\n";
    }
    return $out;
}

/* ============================== I/O sul server ============================== */

function ma_params($docroot)
{
    $file = rtrim($docroot, '/') . '/app/config/parameters.php';
    if (!is_file($file)) {
        throw new MaError("Non trovo $file: docroot errata o PrestaShop < 1.7.");
    }
    $p = include $file;
    if (!isset($p['parameters'])) {
        throw new MaError('parameters.php non valido.');
    }
    return $p['parameters'];
}

function ma_db(array $p)
{
    $host = $p['database_host'];
    $port = !empty($p['database_port']) ? ';port=' . $p['database_port'] : '';
    if (strpos($host, ':') !== false && $port === '') { // host:porta o host:/socket
        list($host, $extra) = explode(':', $host, 2);
        $port = $extra[0] === '/' ? ';unix_socket=' . $extra : ';port=' . $extra;
    }
    return new PDO(
        "mysql:host=$host$port;dbname={$p['database_name']};charset=utf8mb4",
        $p['database_user'],
        $p['database_password'],
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_FOUND_ROWS => true, // rowCount = righe trovate, non solo cambiate
        )
    );
}

function ma_ps_version($docroot)
{
    foreach (array('/app/AppKernel.php', '/src/Core/Version.php', '/config/settings.inc.php') as $f) {
        $c = @file_get_contents($docroot . $f);
        if ($c && preg_match("/(?:const\s+VERSION\s*=|_PS_VERSION_',)\s*'([0-9.]+)'/", $c, $m)) {
            return $m[1];
        }
    }
    return 'sconosciuta';
}

function ma_select($db, $sql, $limit)
{
    $st = $db->query($sql);
    $rows = array();
    $more = false;
    while ($r = $st->fetch()) {
        if ($limit && count($rows) >= $limit) {
            $more = true;
            break;
        }
        $rows[] = $r;
    }
    $st->closeCursor();
    return array($rows, $more);
}

function ma_rmdir_contents($dir)
{
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        $n++;
    }
    return $n;
}

function ma_run($cmd, array $a)
{
    $root = rtrim($a['docroot'], '/');
    $p = ma_params($root);
    $prefix = $p['database_prefix'];

    if ($cmd === 'cache') {
        $dir = $root . '/var/cache';
        echo 'cache svuotata: ' . (is_dir($dir) ? ma_rmdir_contents($dir) : 0) . " elementi rimossi\n";
        return;
    }

    $db = ma_db($p);

    if ($cmd === 'check') {
        echo 'ps_version=' . ma_ps_version($root) . "\n";
        echo 'php_version=' . PHP_VERSION . "\n";
        echo "db_prefix=$prefix\n";
        echo 'db_server=' . $db->query('SELECT VERSION()')->fetchColumn() . "\n";
        echo 'shop_name=' . $db->query("SELECT value FROM {$prefix}configuration WHERE name='PS_SHOP_NAME' LIMIT 1")->fetchColumn() . "\n";
        echo 'multistore=' . ((int) $db->query("SELECT COUNT(*) FROM {$prefix}shop")->fetchColumn() > 1 ? 'si' : 'no') . "\n";
        echo 'cache_scrivibile=' . (is_writable($root . '/var/cache') ? 'si' : 'no') . "\n";
        return;
    }

    if ($cmd === 'schema') {
        if (empty($a['table'])) {
            list($rows) = ma_select($db, "SELECT TABLE_NAME AS tabella, TABLE_ROWS AS righe_circa FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME", 0);
        } else {
            $t = ma_prefix($a['table'], $prefix);
            if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                throw new MaError('Nome tabella non valido.');
            }
            list($rows) = ma_select($db, "SHOW FULL COLUMNS FROM `$t`", 0);
            $rows = array_map(function ($r) {
                return array('campo' => $r['Field'], 'tipo' => $r['Type'], 'null' => $r['Null'], 'chiave' => $r['Key'], 'default' => $r['Default'], 'commento' => $r['Comment']);
            }, $rows);
        }
        echo ma_tsv($rows);
        return;
    }

    if ($cmd === 'query' || $cmd === 'export') {
        list($sql) = ma_guard($a['sql'], 'read');
        list($rows, $more) = ma_select($db, ma_prefix($sql, $prefix), $cmd === 'query' ? (int) $a['limit'] : 0);
        if ($cmd === 'export') {
            $out = fopen('php://output', 'w');
            if ($rows) {
                fputcsv($out, array_keys($rows[0]), ';');
            }
            foreach ($rows as $r) {
                fputcsv($out, $r, ';');
            }
            return;
        }
        echo ma_tsv($rows);
        if ($more) {
            echo "... risultati troncati a {$a['limit']} righe (aggiungi filtri o usa 'ma export')\n";
        }
        return;
    }

    if ($cmd === 'preview' || $cmd === 'apply') {
        list($sql, $masked) = ma_guard($a['sql'], 'write');
        $w = ma_parse_write_masked($sql, $masked);
        $table = ma_prefix($w['table'], $prefix);
        $where = $w['where'] === null ? null : ma_prefix($w['where'], $prefix);
        $sql = ma_prefix($sql, $prefix);

        if ($cmd === 'preview') {
            echo "type={$w['type']}\ntable=$table\n";
            if ($where === null) {
                echo "count=-1\n---CAMPIONE---\n(inserimento: righe nuove)\n";
                return;
            }
            echo 'count=' . $db->query("SELECT COUNT(*) FROM `$table` WHERE $where")->fetchColumn() . "\n";
            list($rows) = ma_select($db, "SELECT * FROM `$table` WHERE $where LIMIT 5", 0);
            echo "---CAMPIONE---\n" . ma_tsv($rows);
            return;
        }

        // apply: transazione, cattura stato precedente, esecuzione, controllo conteggio
        $expected = (int) $a['expected'];
        $db->beginTransaction();
        try {
            $rollback = '';
            if ($where !== null) {
                list($before) = ma_select($db, "SELECT * FROM `$table` WHERE $where FOR UPDATE", 0);
                $rollback = ma_rollback_sql($table, $before);
                if ($w['type'] === 'DELETE' && !$before) {
                    $rollback = '';
                }
            }
            $affected = $db->exec($sql);
            if ($where !== null && $affected !== $expected) {
                throw new MaError("Righe coinvolte $affected, attese $expected (i dati sono cambiati dopo l'anteprima). Nessuna modifica salvata: rifai l'anteprima.");
            }
            if ($where === null) {
                $first = (int) $db->lastInsertId();
                $pk = $db->query("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'")->fetchAll();
                $rollback = ($first && count($pk) === 1)
                    ? "DELETE FROM `$table` WHERE `{$pk[0]['Column_name']}` BETWEEN $first AND " . ($first + $affected - 1) . ";\n"
                    : "-- rollback automatico non disponibile per questo inserimento: contattare bwlab\n";
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        echo "affected=$affected\n---ROLLBACK---\n$rollback";
        return;
    }

    if ($cmd === 'modules') {
        list($rows) = ma_select($db, "SELECT m.name AS modulo, m.version AS versione, m.active AS attivo FROM {$prefix}module m ORDER BY m.name", 0);
        foreach ($rows as &$r) {
            $r['cartella'] = is_dir("$root/modules/{$r['modulo']}") ? 'si' : 'NO';
        }
        echo ma_tsv($rows);
        return;
    }

    throw new MaError("Comando sconosciuto: $cmd");
}

if (!defined('MA_TEST')) {
    try {
        $args = isset($argv[2]) ? json_decode(base64_decode($argv[2]), true) : array();
        if (!is_array($args) || empty($args['docroot'])) {
            throw new MaError('Parametri mancanti (docroot).');
        }
        ma_run(isset($argv[1]) ? $argv[1] : '', $args);
    } catch (MaError $e) {
        fwrite(STDERR, 'ERRORE: ' . $e->getMessage() . "\n");
        exit(3);
    } catch (PDOException $e) {
        fwrite(STDERR, 'ERRORE DATABASE: ' . $e->getMessage() . "\n");
        exit(3);
    }
}
