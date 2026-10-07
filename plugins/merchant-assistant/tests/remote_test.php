<?php
// Self-check della logica pura di remote/ma.php (niente DB, niente rete).
// Uso: php tests/remote_test.php   (deve girare anche con PHP 7.1+)
define('MA_TEST', true);
require __DIR__ . '/../remote/ma.php';

$fail = 0;
function check($label, $cond)
{
    global $fail;
    if (!$cond) {
        $fail++;
        echo "FAIL: $label\n";
    }
}
function guardErr($sql, $mode)
{
    try {
        ma_guard($sql, $mode);
        return null;
    } catch (MaError $e) {
        return $e->getMessage();
    }
}

// --- lettura
check('select ok', guardErr('SELECT * FROM PREFIX_product WHERE id_product = 1', 'read') === null);
check('select ; finale ok', guardErr("SELECT 1;", 'read') === null);
check('show ok', guardErr('SHOW TABLES', 'read') === null);
check('describe ok', guardErr('DESCRIBE PREFIX_product', 'read') === null);
check('update in read negato', guardErr('UPDATE PREFIX_product SET price=1 WHERE id_product=1', 'read') !== null);
check('into outfile negato', guardErr("SELECT * FROM PREFIX_customer INTO OUTFILE '/tmp/x'", 'read') !== null);
check('due istruzioni negate', guardErr('SELECT 1; DELETE FROM PREFIX_cart WHERE 1', 'read') !== null);
check('; dentro stringa ok', guardErr("SELECT * FROM PREFIX_product_lang WHERE name = 'a;b'", 'read') === null);
check('drop in commento ignorato', guardErr("SELECT 1 /* DROP TABLE x */", 'read') === null);
check('drop in stringa ignorato', guardErr("SELECT * FROM PREFIX_cms_lang WHERE content LIKE '%drop table%'", 'read') === null);

// --- scrittura
check('update con where ok', guardErr('UPDATE PREFIX_product SET price = 10 WHERE reference = "ABC"', 'write') === null);
check('delete con where ok', guardErr('DELETE FROM PREFIX_cart_rule WHERE code = "X"', 'write') === null);
check('insert ok', guardErr("INSERT INTO PREFIX_configuration (name, value) VALUES ('A', 'B')", 'write') === null);
check('update senza where negato', guardErr('UPDATE PREFIX_product SET price = 10', 'write') !== null);
check('delete senza where negato', guardErr('DELETE FROM PREFIX_product', 'write') !== null);
check('where solo in stringa negato', guardErr("UPDATE PREFIX_product SET description = 'where' ", 'write') !== null);
check('drop negato', guardErr('DROP TABLE PREFIX_product', 'write') !== null);
check('truncate negato', guardErr('TRUNCATE PREFIX_cart', 'write') !== null);
check('alter negato', guardErr('ALTER TABLE PREFIX_product ADD x INT', 'write') !== null);
check('select in write negato', guardErr('SELECT 1', 'write') !== null);
check('update multi-tabella negato', guardErr('UPDATE PREFIX_product p JOIN PREFIX_stock_available s ON 1 SET p.price=1 WHERE p.id_product=1', 'write') !== null);
check('update con virgola tabelle negato', guardErr('UPDATE PREFIX_product, PREFIX_stock SET price=1 WHERE 1', 'write') !== null);
check('sleep negato', guardErr('UPDATE PREFIX_product SET price = SLEEP(10) WHERE id_product=1', 'write') !== null);

// --- parsing per rollback
$p = ma_parse_write('UPDATE `PREFIX_product` SET price = 10 WHERE reference = "A B" AND active = 1');
check('parse update tabella', $p['type'] === 'UPDATE' && $p['table'] === 'PREFIX_product');
check('parse update where', $p['where'] === 'reference = "A B" AND active = 1');
$p = ma_parse_write("DELETE FROM PREFIX_cart_rule WHERE code = 'X' LIMIT 1");
check('parse delete tabella', $p['type'] === 'DELETE' && $p['table'] === 'PREFIX_cart_rule');
check('parse delete where senza limit', $p['where'] === "code = 'X'");
$p = ma_parse_write("INSERT INTO PREFIX_configuration (name, value) VALUES ('A', 'B')");
check('parse insert', $p['type'] === 'INSERT' && $p['table'] === 'PREFIX_configuration');
$p = ma_parse_write("UPDATE PREFIX_product SET note = 'WHERE x' WHERE id_product = 3");
check('parse where ignora WHERE in stringa', $p['where'] === 'id_product = 3');

// --- prefisso
check('prefisso', ma_prefix('SELECT * FROM PREFIX_product', 'ps_') === 'SELECT * FROM ps_product');

// --- rollback SQL
$sql = ma_rollback_sql('ps_product', array(array('id_product' => '1', 'price' => '9.5', 'note' => "l'x", 'n' => null)));
// INSERT ... ON DUPLICATE KEY UPDATE e non REPLACE: REPLACE fa DELETE+INSERT e farebbe scattare eventuali cascade.
check('rollback upsert', $sql === "INSERT INTO `ps_product` (`id_product`, `price`, `note`, `n`) VALUES ('1', '9.5', 'l\\'x', NULL)"
    . " ON DUPLICATE KEY UPDATE `id_product`=VALUES(`id_product`), `price`=VALUES(`price`), `note`=VALUES(`note`), `n`=VALUES(`n`);\n");
check('rollback vuoto', ma_rollback_sql('ps_product', array()) === '');

echo $fail ? "$fail test falliti\n" : "OK\n";
exit($fail ? 1 : 0);
