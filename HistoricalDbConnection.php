<?php

/**
 * HistoricalDbConnection
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
 */
class HistoricalDbConnection extends CDbConnection
{
	
	public $logHistorical=true;
	
	public function createCommand($query=null) {
		$this->setActive(true);
		$command = new HistoricalDbCommand($this, $query);
		if (!$this->logHistorical) {
			$command->skipHistoricalCommand = true;
		}
		return $command;
	}
	
	public function cleanseTableName($table) {
		return $this->cleanseColumnName($table);
	}
	
	public function cleanseColumnName($column) {
		return preg_replace("/[^a-zA-Z0-9_-]/", "", $column);  // TODO: Make robust
	}
	
	public function cleanseColumnNames(&$columns) {
		foreach ($columns as $key=>$column) {
			$columns[$key] = $this->cleanseColumnName($column);
		}
	}
	
	public function cleanseArrayInts($array) {
		if (!is_array($arr)) {
			return (int)$arr;
		}
		$arrOut = array();
		foreach ($arr as $val) {
			$arrOut[] = (int)$val;
		}
		return $arrOut;		
	}
	
}
