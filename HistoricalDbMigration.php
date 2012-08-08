<?php

/**
 * HistoricalDbMigration
 *
 * PHP version 5.2+
 * MySQL version 5+
 *
 * @author    Paul Lowndes <github@gtcode.com>
 * @author    GTCode
 * @link      http://www.GTCode.com/
 * @package   historical-db
 * @version   0.01a
 * @category  ext*
 *
 * NOTE:  All constructs changing schema are transaction unsafe in MySQL.
 *        Thus, the transactions are mostly pointless, anyway.
 *
 * See: https://github.com/yiisoft/yii/issues/805
 *
 */
class HistoricalDbMigration extends CDbMigration
{

	public $historicalConnectionID = 'dbHistorical';
	private $_originalDbConnection = null;

	private $_transactions = null;

	public function createTable($table, $columns, $options=null, $skipHistorical=false) {
		parent::createTable($table, $columns, $options);
		if ($skipHistorical !== false || !$this->getDbConnection()->logHistorical) {
			return;
		}
		$historicalTable = $this->getHistoricalTableName($table, false);
		$pk = '';
		$historicalColumns = null;
		foreach ($columns as $column=>$type) {
			$type = strtolower($type);
			if ($type === 'pk') {
				if ($pk !== '') {
					throw new CException('Multiple PK definitions detected, disallowed.');
				}
				$pk = substr(Yii::app()->params['historicalDbPrefix'] . '_' . $column, 0, 64);
				$columns[$column] = 'INT(11) NOT NULL';
			} else if (stripos($type, 'timestamp') !== false) {
				$columns[$column] = 'TIMESTAMP NULL';
			} else if (stripos($type, 'datetime') !== false) {
				$columns[$column] = 'DATETIME NULL';
			} else if (stripos($type, 'primary key') !== false) {
				if ($pk !== '') {
					throw new CException('Multiple PK definitions detected, this is disallowed.');
				}
				$startPos = stripos($type,'(');
				$endPos = stripos($type,')');
				if ($startPos === false || $endPos === false) {
					throw new CException('Format of composite primary key is invalid.');
				}
				$inner = str_replace(',','_',substr($type,$startPos+1, $endPos-$startPos-2));
				$inner = str_replace(' ', '', $inner);
				$inner = str_replace('`', '', $inner);
				$pk = substr($inner, 0, 64);
			}
		}
		if ($pk !== '') {
			$historicalColumns = CMap::mergeArray(
				array($pk=>'pk'),
				$columns,
				array(
					'historical_user_id' => 'INT(11) DEFAULT NULL',
					'historical_timestamp' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP()',
					'historical_action' => 'ENUM("INSERT","UPDATE","DELETE") DEFAULT "INSERT"',
				)
			);
		} else {
			throw new CException('No suitable PK!');
		}
		$this->setDbToHistorical();
		parent::createTable($historicalTable, $historicalColumns, $options);
		$this->setDbToOriginal();
	}

	public function createHistoricalTable($table) {
		$historicalTable = $this->getHistoricalTableName($table, true, true);
		if ($historicalTable === false) {
			throw new CException('The historical table for the table named ' . $table . ' seems to already exist!');
		}
		$schema = $this->dbConnection->schema;
		$columns = $schema->getTable($table)->columns;
		$cols=array();
		$options = 'ENGINE = INNODB DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci';
		$primaryKeys = array();
		foreach ($columns as $column) {
			if ($column->isPrimaryKey) {
				$primaryKeys[] = $column->name;
			}
			$sep = is_string($column->defaultValue) ? "'" : '';
			$encoding = stripos($column->dbType, 'varchar') ? '  CHARACTER SET utf8 COLLATE utf8_unicode_ci ' : '';
			if ($column->dbType === 'timestamp' || $column->dbType === 'datetime') {
				$cols[$column->name] = $column->dbType . ' NULL';
			} else {
				$cols[$column->name] =
					$column->dbType . $encoding
					. ( ($column->allowNull) ? ' NULL' : ' NOT NULL')
					. ( ($column->defaultValue!==null) ? ' DEFAULT ' . $sep . $column->defaultValue . $sep : (($column->allowNull && $column->defaultValue===null) ? ' DEFAULT NULL' : ''));
			}
		}
		if (count($primaryKeys)==0) {
			throw new CException('No primary keys found, not currently supported!');
		} else if (count($primaryKeys)==1) {
			$pk = substr('h_' . $primaryKeys[0], 0, 64);
		} else {
			$pk = substr(implode('_',$primaryKeys), 0, 64);
		}
		$cols = CMap::mergeArray(
			array($pk=>'pk'),
			$cols,
			array(
				'historical_user_id' => 'INT(11) DEFAULT NULL',
				'historical_timestamp' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP()',
				'historical_action' => 'ENUM("INSERT","UPDATE","DELETE") DEFAULT "INSERT"',
			)
		);
		$this->setDbToHistorical();
		parent::createTable(
			$historicalTable,
			$cols,
			$options
		);
		$this->setDbToOriginal();
	}

	public function renameTable($table, $newName) {
		parent::renameTable($table, $newName);
		if (!$this->getDbConnection()->logHistorical) {
			return;
		}
		$historicalTable = $this->getHistoricalTableName($table);
		$historicalTableNew = $this->getHistoricalTableName($newName, false);
		if ($historicalTable) {
			$this->setDbToHistorical();
			parent::renameTable($historicalTable, $historicalTableNew);
			$this->setDbToOriginal();
		}
	}

	/**
	 * By Default, the historical table will not be dropped!
	 */
	public function dropTable($table, $dropHistorical=false) {
		parent::dropTable($table);
		if (!$this->getDbConnection()->logHistorical) {
			return;
		}
		$historicalTable = $this->getHistoricalTableName($table);
		if ($historicalTable && $dropHistorical) {
			$this->setDbToHistorical();
			parent::dropTable($historicalTable);
			$this->setDbToOriginal();
		}
	}

	/**
	 * TODO:  Add support for 'AFTER' and 'FIRST' in $type field.
	 * TODO:  Add support for adding a primary key (this is not a common occurrence, and should not be needed).
	 */
	public function addColumn($table, $column, $type) {
		parent::addColumn($table, $column, $type);
		if (!$this->getDbConnection()->logHistorical) {
			return;
		}
		$historicalTable = $this->getHistoricalTableName($table);
		if ($historicalTable) {
			$this->setDbToHistorical();
			$type = strtolower($type);
			if ($type === 'pk') {
				$type = 'int(11) NOT NULL';
			} else if ($type === 'int(11) not null auto_increment primary key first') {  // This is a special case that should not occur if we avoid composite FK's and avoid changing PK's after the fact..
				$parentCol = substr('h_' . $column, 0, 64);
				parent::addColumn($historicalTable, $parentCol, 'INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
				$type = 'INT(11) NOT NULL AFTER ' . $parentCol;
			} else if (stripos($type, 'timestamp') !== false) {
				$type = 'TIMESTAMP NULL';
			} else if (stripos($type, 'datetime') !== false) {
				$type = 'DATETIME NULL';
			}
			parent::addColumn($historicalTable, $column, $type);
			$this->setDbToOriginal();
		}
	}

	/**
	 * TODO:  Add support for 'AFTER' and 'FIRST' in $type field.
	 * TODO:  Add support for altering a primary key (this is not a common occurrence, and should not be needed).
	 */
	public function alterColumn($table, $column, $type) {
		parent::alterColumn($table, $column, $type);
		if (!$this->getDbConnection()->logHistorical) {
			return;
		}
		$historicalTable = $this->getHistoricalTableName($table);
		if ($historicalTable) {
			$this->setDbToHistorical();
			parent::alterColumn($historicalTable, $column, $type);
			$this->setDbToOriginal();
		}
	}

	public function renameColumn($table, $name, $newName) {
		parent::renameColumn($table, $name, $newName);
		if (!$this->getDbConnection()->logHistorical) {
			return;
		}
		$historicalTable = $this->getHistoricalTableName($table);
		if ($historicalTable) {
			$this->setDbToHistorical();
			parent::renameColumn($historicalTable, $name, $newName);
			$this->setDbToOriginal();
		}
	}

	/**
	 * By Default, the historical column will not be dropped!
	 */
	public function dropColumn($table, $name, $dropHistorical=false) {
		parent::dropColumn($table, $name);
		if (!$this->getDbConnection()->logHistorical || !$dropHistorical) {
			return;
		}
		$historicalTable = $this->getHistoricalTableName($table);
		if ($historicalTable) {
			$this->setDbToHistorical();
			parent::dropColumn($historicalTable, $name);
			$this->setDbToOriginal();
		}
	}

	/**
	 * CAUTION - USE WITH CARE!  THIS IS NOT TO BE USED NORMALLY!
	 */
	public function dropColumnHistorical($table, $name) {
		if (!$this->getDbConnection()->logHistorical) {
			return;
		}
		$this->setDbToHistorical();
		parent::dropColumn($table, $name);
		$this->setDbToOriginal();
	}

	public function setDbToHistorical() {
		echo '    > Switching to Historical DB Connection ...';
		$time=microtime(true);
		if ($this->_originalDbConnection === null) {
			$this->_originalDbConnection = $this->getDbConnection();
		}
		$this->setDbConnection($this->getHistoricalConnection());
		echo " done (time: ".sprintf('%.3f', microtime(true)-$time)."s)\n";
	}

	public function setDbToOriginal() {
		echo '    > Switching to Original DB Connection. ...';
		$time=microtime(true);
		if ($this->_originalDbConnection === null) {
			$this->_originalDbConnection = $this->getDbConnection();
		}
		$this->setDbConnection($this->_originalDbConnection);
		echo " done (time: ".sprintf('%.3f', microtime(true)-$time)."s)\n";
	}

	public function getHistoricalTableName($table, $confirm=true, $invert=false) {
		if (substr($table,0,2) !== 'p_') {
			throw new CException('During Migration, table did not prefix with "p_".  Chosen name was: ' . $table);
		}
		$historicalTableName = Yii::app()->params['historicalDbPrefix'] . substr($table,1);
		$dbHistorical = $this->getHistoricalConnection();
		if ($confirm) {
			$hasTable = $this->getHistoricalConnection()->createCommand("
				SELECT COUNT(*)
				FROM information_schema.tables
				WHERE table_schema = '" . $this->getDbName($dbHistorical) . "'
				AND table_name = '" . $db->cleanseTableName($historicalTableName) . "'
				")->queryScalar();
			if ( (!$invert && !$hasTable) || ($invert && $hasTable) ){
				return false;
			}
		}
		return $historicalTableName;
	}

	public function getHistoricalConnection() {
		return Yii::app()->getComponent($this->historicalConnectionID);
	}

	public function up() {
		$this->startTransactions();
		try {
			if($this->safeUp()===false) {
				$this->rollBackTransactions();
				return false;
			}
			$this->commitTransactions();
		} catch(Exception $e) {
			echo "Exception: ".$e->getMessage().' ('.$e->getFile().':'.$e->getLine().")\n";
			echo $e->getTraceAsString()."\n";
			$this->rollBackTransactions();
			return false;
		}
	}

	public function down() {
		$this->startTransactions();
		try {
			if($this->safeDown()===false) {
				$this->rollBackTransactions();
				return false;
			}
			$this->commitTransactions();
		} catch(Exception $e) {
			echo "Exception: ".$e->getMessage().' ('.$e->getFile().':'.$e->getLine().")\n";
			echo $e->getTraceAsString()."\n";
			$this->rollBackTransactions();
			return false;
		}
	}

	private function startTransactions() {
		$this->_transactions = array(
			'transaction' => $this->getDbConnection()->beginTransaction(),
			'transactionHistorical' => $this->getHistoricalConnection()->beginTransaction(),
		);
	}

	private function commitTransactions() {
		$this->_transactions['transaction']->commit();
		$this->_transactions['transactionHistorical']->commit();
	}

	private function rollBackTransactions() {
		$this->_transactions['transaction']->rollBack();
		$this->_transactions['transactionHistorical']->rollBack();
	}

	/**
	 * This will work with MySQL but probably not others, as connection string
	 * format is not universal.  TODO:  Make more robust/generic.
	 */
	public function getDbName($connection=null) {
		if ($connection===null) {
			$dbName = $this->getDbConnection()->connectionString;
		} else {
			$dbName = $connection->connectionString;
		}
		$dbLoc = stripos($dbName,'dbname=');
		if ($dbLoc === false) {
			throw new CException('Cannot find DB Name, connection string: ' . $dbName);
		}
		return trim(substr($dbName,$dbLoc+7));
	}

}
