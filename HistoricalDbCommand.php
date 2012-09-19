<?php

/**
 * HistoricalDbCommand
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
 * Instead of CDbCommand::execute(), use the named variations in this method.
 *
 * CDbCommand::insert(), CDbCommand::update(), and CDbCommand::delete() support
 * automatic logging of historical record.
 *
 */
class HistoricalDbCommand extends CDbCommand
{

	/**
	 * Name of the Historical DB Connection
	 */
	public $historicalConnectionID = 'dbHistorical';

	/**
	 * Whether to skip historical record creation
	 */
	public $skipHistoricalCommand = false;

	/**
	 * Insert into intended table, then create historical INSERT record.
	 */
	public function insert($table, $columns) {
		if ($this->skipHistoricalCommand) {
			return parent::insert($table, $columns);
		}
		$db = $this->getConnection();
		$this->skipHistoricalCommand = true;
		$ret = parent::insert($table, $columns);
		$this->skipHistoricalCommand = false;
		$this->logHistoricalInsert($db, $table);
		return $ret;
	}

	/**
	 * Update intended record, then create historical UPDATE record.
	 */
	public function update($table, $columns, $conditions='', $params=array()) {
		if ($this->skipHistoricalCommand) {
			return parent::update($table, $columns, $conditions, $params);
		}
		$db = $this->getConnection();
		$tbl = $db->getSchema()->getTable($table);
		if ($tbl === null) {
			throw new CDbException(
				Yii::t('yii','The table "{table}" for active record class "{class}" cannot be found in the database.', array(
						'{class}'=>get_class($model),
						'{table}'=>$table,
				))
			);
		}
		$q = "
			SELECT `" . $tbl->primaryKey . "`
			FROM " . $db->quoteTableName($table) . "
		";
		$primaryKeys = $db->createCommand($q)->where($conditions, $params)->queryColumn();
		if (count($primaryKeys) == 0) {
			return 0;
		}
		$this->skipHistoricalCommand = true;
		$ret = parent::update($table, $columns, $conditions, $params);
		$this->skipHistoricalCommand = false;
		$changedRows = $this->getChangedRowsForHistorical($table, $tbl->primaryKey, $primaryKeys);
		$this->logHistoricalUpdateRows($table, $changedRows);
		return $ret;
	}

	/**
	 * Create historical delete record, then hard DELETE original record.
	 */
	public function delete($table, $conditions='', $params=array()) {
		if ($this->skipHistoricalCommand) {
			return parent::delete($table, $conditions, $params);
		}
		$db = $this->getConnection();
		$q = "
			SELECT *
			FROM " . $db->quoteTableName($table) . "
		";
		$changedRows = $db->createCommand($q)->where($conditions, $params)->queryAll();
		if (count($changedRows) == 0) {
			return 0;
		}
		$this->logHistoricalDeleteRows($table, $changedRows);
		$this->skipHistoricalCommand = true;
		$ret = parent::delete($table, $conditions, $params);
		$this->skipHistoricalCommand = false;
		return $ret;
	}

	/**
	 * Use this in place of execute for INSERT statements.
	 * It will both execute the INSERT, and log the historical record.
	 */
	public function executeHistoricalInsert($table) {
		if ($this->skipHistoricalCommand) {
			return $this->execute();
		}
		$db = $this->getConnection();
		$ret = $this->execute();
		$this->logHistoricalInsert($db, $table);
		return $ret;
	}

	/**
	 * Use this in place of execute for UPDATE statements.
	 * It will both execute the UPDATE, and log the historical record.
	 * See HistoricalDbCommand::getChangedRowsForHistorical for explanation of parameters.
	 */
	public function executeHistoricalUpdate($table, $keys, $values) {
		if ($this->skipHistoricalCommand) {
			return $this->execute();
		}
		$db = $this->getConnection();
		$ret = $this->execute();
		$this->logHistoricalUpdate($table, $keys, $values);
		return $ret;
	}

	/**
	 * Use this in place of execute for DELETE statements.
	 * It will both (first) log the historical delete, then execute the hard DELETE.
	 *
	 * TODO:  Move call to $this->execute() into logHistoricalDelete, so we can commit it right after deleting, but before performing the actual historical logging.
	 *
	 * See HistoricalDbCommand::getChangedRowsForHistorical() for explanation of parameters.
	 *
	 * @param string $table
	 * @param mixed $keys
	 * @param mixed $values
	 * @return int number of deleted rows
	 */
	public function executeHistoricalDelete($table, $keys, $values) {
		if ($this->skipHistoricalCommand) {
			return $this->execute();
		}
		$db = $this->getConnection();
			$this->logHistoricalDelete($table, $keys, $values);
			$ret = $this->execute();
			return $ret;
	}

	/**
	 * Creates a historical update for given pk/values.  Original update not performed.
	 * See HistoricalDbCommand::getChangedRowsForHistorical() for explanation of parameters.
	 * @param string $table
	 * @param mixed $keys
	 * @param mixed $values
	 * @param int number of updated rows
	 */
	public function createHistoricalUpdate($table, $keys, $values) {
		if ($this->skipHistoricalCommand) {
			return;
		}
		$db = $this->getConnection();
		$this->logHistoricalUpdate($table, $keys, $values);
	}

	/**
	 * Logs the state of a record to the historical table before deletion.  Original delete not performed.
	 * See HistoricalDbCommand::getChangedRowsForHistorical() for explanation of parameters.
	 * @param string $table
	 * @param mixed $keys
	 * @param mixed $values
	 */
	public function createHistoricalDelete($table, $keys, $values) {
		if ($this->skipHistoricalCommand) {
			return;
		}
		$this->logHistoricalDelete($table, $keys, $values);
	}

	/**
	 * Logs an INSERT ON DUP KEY UPDATE statement, and creates the appropriate historical entry.
	 *
	 * Expects either 0 or 1 rows to exist with the unique key, thus this should be
	 * enforced via unique index in the source table.
	 *
	 * @param string $table
	 * @param mixed $keys
	 * @param mixed $values
	 * @return int number of affected rows
	 * See HistoricalDbCommand::getChangedRowsForHistorical for explanation of parameters.
	 */
	public function executeHistoricalInsertOnDuplicateKeyUpdate($table, $keys, $values) {
		if ($this->skipHistoricalCommand) {
			return $this->execute();
		}
		$db = $this->getConnection();
		$changedRows = $this->getChangedRowsForHistorical($table, $keys, $values);
		if (count($changedRows) == 0) { // insert
			$ret = $this->execute();
			$this->logHistoricalInsert($db, $table);
		} else if (count($changedRows) == 1) { // duplicate key, update
			$tbl = $db->getSchema()->getTable($table);
			if ($tbl === null) {
				throw new CDbException(
					Yii::t('yii','The table "{table}" for active record class "{class}" cannot be found in the database.', array(
							'{class}'=>get_class($model),
							'{table}'=>$table,
					))
				);
			}
			$ret = $this->execute();
			if (!isset($changedRows[0][$tbl->primaryKey])) {
				throw new CDbException('Could not find pk for insert on historical update, table: ' . $table . ', primaryKey: ' . $tbl->primaryKey . ', values: ' . print_r($changedRows,true));
			}
			$changedRows = $this->getChangedRowsForHistorical($table, $tbl->primaryKey, $changedRows[0][$tbl->primaryKey]);
			$this->logHistoricalUpdateRows($table, $changedRows);
		} else {
			throw new CException(
				'INSERT ON DUPLICATE KEY UPDATE had more than one result.
				Table: ' . $table . ',
				Keys: ' . print_r($keys,true) . ',
				Values: ' . print_r($values,true) . ',
				Results: ' . print_r($changedRows,true));
		}
		return $ret;
	}

	/**
	 * An insert should always generate a last insert id, and we use non-composite PK's only.
	 * Thus, this one method should be sufficient to cover historical logging of all PDO inserts.
	 *
	 * @param CDbConnection $db The connection object used to insert the record originally.
	 * @param string $table The name of the table where the insert was performed
	 */
	private function logHistoricalInsert($db, $table) {
		$tbl = $db->getSchema()->getTable($table);
		if ($tbl === null) {
			throw new CDbException(
				Yii::t('yii','The table "{table}" for active record class "{class}" cannot be found in the database.', array(
						'{class}'=>get_class($model),
						'{table}'=>$table,
				))
			);
		}
		$this->logHistoricalCommand($table, $tbl->primaryKey, $db->getLastInsertId(), 'INSERT');
	}

	private function logHistoricalUpdate($table, $keys, $values) {
		$this->logHistoricalCommand($table, $keys, $values, 'UPDATE');
	}

	private function logHistoricalDelete($table, $keys, $values) {
		$this->logHistoricalCommand($table, $keys, $values, 'DELETE');
	}

	private function logHistoricalCommand($table, $keys, $values, $action) {
		$changedRows = $this->getChangedRowsForHistorical($table, $keys, $values);
		$this->logHistoricalRows($table, $action, $changedRows);
	}

	private function logHistoricalUpdateRows($table, $changedRows) {
		$this->logHistoricalRows($table, 'UPDATE', $changedRows);
	}

	private function logHistoricalDeleteRows($table, $changedRows) {
		$this->logHistoricalRows($table, 'DELETE', $changedRows);
	}

	private function logHistoricalRows($table, $action, $changedRows) {
		foreach ($changedRows as $changedRow) {
			$this->insertHistorical($table, $changedRow, $action);
		}
	}

	private function insertHistorical($table, $columns, $action) {
		if (substr($table,0,2) === 'p_') {
			$dbHistorical = $this->getHistoricalConnection();
			$params=array();
			$names=array();
			$placeholders=array();
			foreach($columns as $name=>$value) {
				$names[]=$dbHistorical->quoteColumnName($name);
				if($value instanceof CDbExpression) {
					$placeholders[] = $value->expression;
					foreach($value->params as $n => $v) {
						$params[$n] = $v;
					}
				} else {
					$placeholders[] = ':' . $name;
					$params[':' . $name] = $value;
				}
			}
			$sql='INSERT INTO ' . $dbHistorical->quoteTableName(Yii::app()->params['historicalDbPrefix'] . substr($table,1))
				. ' (' . implode(',', $names) . ',
					historical_user_id,
					historical_action
					) VALUES ('
				. implode(',', $placeholders) . ',
					:historical_user_id,
					:historical_action
				)';
			$params[':historical_user_id'] = Yii::app()->user->id;
			$params[':historical_action'] = $action;
			$dbHistorical->createCommand($sql)->execute($params);
		}
	}

	/**
	 * Only works with integer keys for IN condition at this time, parameter binding large arrays could be too expensive.
	 * TODO: Clean this mess up
	 *
	 * @param string $table Name of the table, unquoted.
	 * @param mixed $keys If String, name of PK or Unique Key.  If array, array of composite unique key or pk.
	 * @param mixed $values If String, value of PK or Unique Key.  If non-associative array, IN clause for changed rows.  If associative, key is name of PK(s), and value can be string or array.
	 * @return array results of queryAll()
	 */
	private function getChangedRowsForHistorical($table, $keys, $values) {
		$db = $this->getConnection();
		$q = "
			SELECT *
			FROM " . $db->quoteTableName($table) . "
			WHERE
		";
		$params = array();
		if (!is_array($keys)) {
			$key = $keys;
			$q .= $key;
			if (!is_array($values)) {
				$q .= '= :' . $key;
				$params[':' . $key] = $values;
			} else if (isset($values[$key])) {
				if (!is_array($values[$keys])) {
					$q .= '= :' . $key;
					$params[':' . $key] = $values[$key];
				} else {
					foreach ($values[$key] as $valueKey) {
						if (!is_numeric($valueKey)) {
							throw new CException('Non integer key values for historical update IN condition not yet supported, key: ' . $key . ', value: ' . $values[$key]);
						}
					}
					$q .= ' IN (' . implode(',', $db->cleanseArrayInts($values[$key])) . ") ";
				}
			} else {
				foreach ($values as $valueKey) {
					if (!is_numeric($valueKey)) {
						throw new CException('Non integer key values for historical update IN condition not yet supported, key: ' . $key . ', value: ' . $values[$key]);
					}
				}
				$q .= ' IN (' . implode(',', $db->cleanseArrayInts($values)) . ") ";
			}
		} else if (is_array($keys)) {
			$sep = '';
			foreach ($keys as $key) {
				$q .= $sep . ' ' . $key;
				if (!is_array($values)) {
					throw new CException('Malformed usage, values not an array for multiple keys ' . $key);
				}
				if (!isset($values[$key])) {
					throw new CException('Malformed usage, value not present for (array) key: ' . $key);
				}
				if (!is_array($values[$key])) {
					$q .= '= :' . $key;
					$params[':' . $key] = $values[$key];
				} else {
					foreach ($values[$key] as $valueKey) {
						if (!is_numeric($valueKey)) {
							throw new CException('Non integer key values for historical update IN condition not yet supported, key: ' . $key . ', value: ' . $values[$key]);
						}
					}
					$q .= ' IN (' . implode(',', $db->cleanseArrayInts($values[$key])) . ") ";
				}
				$sep = ' AND';
			}
		} else {
			throw new CException('Improper format.  Please check your syntax.');
		}
		$command = $db->createCommand($q);
		foreach ($params as $key=>$value) {
			$command->bindValue($key, $value);
		}
		return $command->queryAll();
	}

	private function getHistoricalConnection() {
		return Yii::app()->getComponent($this->historicalConnectionID);
	}

}
