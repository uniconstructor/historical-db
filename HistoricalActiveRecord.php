<?php

/**
 * HistoricalActiveRecord
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
 * This class is used to automatically archive all incremental changes to a log
 * table with the same name as the base table, but starting with z_ instead.
 * The class HistoricalDbMigration handles automatic creation and maintenance of
 * these tables.  Please see that class for more info.
 *
 * In short, we must use HistoricalActiveRecord or HistoricalDbCommand, but
 * never modify the database directly.  Failing to adhere to this will cause a
 * break in integrity of record history.
 *
 */
class HistoricalActiveRecord extends CActiveRecord
{

	public $historicalConnectionID = 'dbHistorical';

	const PARAM_PREFIX=':yp';

	public function afterSave() {
		if ($this->isNewRecord) {
			$this->insertHistorical();
		} else {
			$this->updateHistorical();
		}
		return parent::afterSave();
	}

	public function beforeDelete() {
		$this->deleteHistorical();
		return parent::beforeDelete();
	}

	public function insertHistorical() {
		$this->createHistoricalRow('INSERT', $this);
	}

	public function updateHistorical() {
		$this->createHistoricalRow('UPDATE', $this);
	}

	public function deleteHistorical() {
		$this->createHistoricalRow('DELETE', $this);
	}

	private function createHistoricalRow($action, $model) {
		$table = $model->getMetaData()->tableSchema;
		$historicalTableName = $this->getHistoricalTableName($table->name);
		if ($historicalTableName === false) {
			throw new CException('Historical class is applied to table without historical table.  Original table: ' . $table->name);
		}
		$data = $model->getAttributes();
		$primaryKey = $table->primaryKey;
		if (!is_string($table->primaryKey)) {
			$primaryKey = substr(implode('_', $table->primaryKey), 0, 64);
		} else {
			$primaryKey = substr('h_' . $primaryKey, 0, 64);
		}
		$fields = array();
		$values = array();
		$placeholders = array();
		$i=0;
		foreach ($data as $name=>$value) {
			if (($column=$table->getColumn($name)) !== null && ($value !== null || $column->allowNull)) {
				$fields[] = $column->rawName;
				if ($value instanceof CDbExpression) {
					$placeholders[] = $value->expression;
					foreach ($value->params as $n=>$v) {
						$values[$n] = $v;
					}
				} else {
					$placeholders[] = self::PARAM_PREFIX.$i;
					$values[self::PARAM_PREFIX.$i] = $column->typecast($value);
					$i++;
				}
			}
		}
		if ($fields === array()) {
			$pks = is_array($table->primaryKey) ? $table->primaryKey : array($table->primaryKey);
			foreach ($pks as $pk) {
				$fields[] = $table->getColumn($pk)->rawName;
				$placeholders[] = 'NULL';
			}
		}
		$dbHistorical = $this->getHistoricalConnection();
		$q = "
			INSERT INTO " . $dbHistorical->quoteTableName($historicalTableName) . "
			(	" . $dbHistorical->cleanseColumnName($primaryKey) . ",
				" . implode(', ', $dbHistorical->cleanseColumnNames($fields)) . ",
				historical_user_id,
				historical_action
			) VALUES (
				null,
				" .implode(', ', $placeholders) . ',
				:historical_user_id,
				:historical_action
			)';
		$command = $dbHistorical->createCommand($q);
		foreach ($values as $name=>$value) {
			$command->bindValue($name,$value);
		}
		$command->bindValue(':historical_user_id', Yii::app()->user->id);
		$command->bindValue(':historical_action', $action);
		$command->execute();
	}

	private function getHistoricalTableName($table, $confirm=true) {
		$historicalName = Yii::app()->params['historicalDbPrefix'] . substr($table,1);
		$dbHistorical = $this->getHistoricalConnection();
		if ($confirm && !$dbHistorical->createCommand("
				SELECT COUNT(*)
				FROM information_schema.tables
				WHERE table_schema = '" . $this->getDbName($this->historicalConnectionID) . "' 
				AND table_name = '" . $dbHistorical->cleanseTableName($historicalName) . "'
			")->queryScalar()) {
			return false;
		}
		return $historicalName;
	}

	public function getDbName($dbComponent='db') {
		$dbName = Yii::app()->getComponent($dbComponent)->connectionString;
		$dbLoc = stripos($dbName,'dbname=');
		if ($dbLoc === false) {
			throw new CException('Cannot find DB Name.');
		}
		return trim(substr($dbName,$dbLoc+7));
	}

	public function getHistoricalConnection() {
		return Yii::app()->getComponent($this->historicalConnectionID);
	}

	/**
	 * Override this function and return an array of Models that should
	 * be deleted prior to $this.  If the key name is different than the primary
	 * key of the related model, express this as $keyName=>$modelName.
	 *
	 * @return array Restricted Models to be deleted before $this one.
	 */
	public function restrictedModels() {
		return array();
	}

	/**
	 * TODO: Make this much faster, as it is vastly inefficient.
	 * We likely need a queueing system to handle historical record creation
	 * of large sets of db records that are modified by a single statement.
	 */
	public function deleteAll($condition='', $params=array()) {
		$models = $this->findAll($condition, $params);
		$count = 0;
		foreach ($models as $model) {
			$count += $model->delete();
		}
		return $count;
	}

	/**
	 * TODO: Make this much faster, as it is vastly inefficient.
	 * We likely need a queueing system to handle historical record creation
	 * of large sets of db records that are modified by a single statement.
	 */
	public function deleteAllByAttributes($attributes, $condition='', $params=array()) {
		$models = $this->findAllByAttributes($attributes, $condition, $params);
		$count = 0;
		foreach ($models as $model) {
			$count += $model->delete();
		}
		return $count;
	}

	/**
	 * TODO: Make this much faster, as it is vastly inefficient.
	 * We likely need a queueing system to handle historical record creation
	 * of large sets of db records that are modified by a single statement.
	 */
	public function updateAll($attributes, $condition='', $params=array()) {
		$models = $this->findAll($condition, $params);
		$count = 0;
		foreach ($models as $model) {
			$count += $model->update($attributes);
		}
		return $count;
	}

	/**
	 * This function is used internally by CActiveRecord.  In this case
	 * we do not want to disturb the normal functionality as the calling method
	 * has already raised beforeDelete which we need to create the historical
	 * record.  However, if we use this externally (i.e. our app), we need to
	 * trigger the historical record creation.
	 *
	 * TODO: Pass in callback for parent::deleteByPk, so we can commit the
	 *       transaction directly after we have deleted the record, but before
	 *       logging to the historical table; this will be a bit more efficient.
	 */
	public function deleteByPk($pk, $condition='', $params=array()) {
		if (!$this->isCalledPrivately()) {  // We need to add a historical log entry
			try {
				$transaction = $this->getDbConnection()->beginTransaction();
				$table = $this->getMetaData()->tableSchema;
				$this->getDbConnection()->createCommand()->createHistoricalDelete($table->name, $table->primaryKey, $pk);
				$ret = parent::deleteByPk($pk, $attributes, $condition, $params);
				$transaction->commit();
				return $ret;
			} catch (Exception $e) {
				$transaction->rollback();
				throw new CException('Error during transaction, message: ' . $e->getMessage());
			}
		}
		return parent::deleteByPk($pk, $condition, $params);
	}

	/**
	 * This function is used internally by CActiveRecord.  In this case
	 * we do not want to disturb the normal functionality as the calling method
	 * has already raised afterSave which we need to create the historical
	 * record.  However, if we use this externally (i.e. in our app), we need to
	 * trigger the historical record creation.
	 */
	public function updateByPk($pk, $attributes, $condition='', $params=array()) {
		if (!$this->isCalledPrivately()) {  // We need to add a historical log entry
			try {
				$transaction = $this->getDbConnection()->beginTransaction();
				$ret = parent::updateByPk($pk, $attributes, $condition, $params);
				$table = $this->getMetaData()->tableSchema;
				$this->getDbConnection()->createCommand()->createHistoricalUpdate($table->name, $table->primaryKey, $pk, $transaction);
				return $ret;
			} catch (Exception $e) {
				$transaction->rollback();
				throw new CException('Error during transaction, message: ' . $e->getMessage());
			}
		}
		return parent::updateByPk($pk, $attributes, $condition, $params);
	}

	public function saveCounters($counters) {
		throw new CException('Method deactivated'); // TODO: Integrate historical record creation before re-activating.
	}

	public function updateCounters($counters, $condition='', $params=array()) {
		throw new CException('Method deactivated'); // TODO: Integrate historical record creation before re-activating.
	}

	public function saveAttributes($array) {
		throw new CException('Method deactivated'); // TODO: Integrate historical record creation before re-activating.
	}

	private function isCalledPrivately() {
		$backtrace = debug_backtrace();
		if (count($backtrace) < 3) {
			return false;
		}
		if ($backtrace[2]['class'] !== 'CActiveRecord' && $backtrace[2]['class'] !== get_class($this)) {
			return false;
		}
		return true;
	}

}
