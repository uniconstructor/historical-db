<?php

/**
 * HistoricalDbPDO
 *
 * PHP version 5.2+
 * MySQL version 5+
 *
 * @author    Paul Lowndes <github@gtcode.com>
 * @author    GTCode
 * @link      http://www.GTCode.com/
 * @package   historical-db
 * @version   0.01b
 * @category  ext*
 *
 * Derived from: http://www.yiiframework.com/wiki/38/how-to-use-nested-db-transactions-mysql-5-postgresql/
 *
 */
class HistoricalDbPDO extends PDO
{

    // Database drivers that support SAVEPOINTs.
    protected static $savepointTransactions = array("pgsql", "mysql");

    // The current transaction level.
    protected $transLevel = 0;

    protected function nestable() {
        return in_array($this->getAttribute(PDO::ATTR_DRIVER_NAME),
                        self::$savepointTransactions);
    }

    public function beginTransaction() {
        if ($this->transLevel == 0 || !$this->nestable()) {
            parent::beginTransaction();
        } else {
            $this->exec("SAVEPOINT LEVEL{$this->transLevel}");
        }
        $this->transLevel++;
    }

    public function commit() {
        $this->transLevel--;
        if ($this->transLevel == 0 || !$this->nestable()) {
            parent::commit();
        } else {
            $this->exec("RELEASE SAVEPOINT LEVEL{$this->transLevel}");
        }
    }

    public function rollBack() {
        $this->transLevel--;
        if ($this->transLevel == 0 || !$this->nestable()) {
            parent::rollBack();
        } else {
            $this->exec("ROLLBACK TO SAVEPOINT LEVEL{$this->transLevel}");
        }
    }

}
