historical-db
=============

Yii Components for managing historical database records.

Introduction
---------
historical-db is a collection of components for [Yii](http://www.yiiframework.com/) that allow for automatic creation of historical database records at the application layer.  ActiveRecord and DAO are both supported.

###Requirements

* Yii 1.1 or above
* PHP 5.2+
* MySQL 5+

###Installation

Create a new separate historical DB to correspond to the dbHistorical component below.
Then, in main.php, you will need two main DB connections inside 'components':
```
	'components'=>array(
		// ...
		'db'=>array(
			'pdoClass'=>'HistoricalDbPDO',
			'class'=>'HistoricalDbConnection',
			'logHistorical'=>true,
			'connectionString'=>'mysql:host=localhost;dbname=database_name',  // Edit
			'emulatePrepare'=>true,
			'username'=>'database_username',  // Edit
			'password'=>'database_password',  // Edit
		),
		'dbHistorical'=>array(
			'pdoClass'=>'HistoricalDbPDO',
			'class'=>'HistoricalDbConnection',
			'logHistorical'=>false,
			'connectionString'=>'mysql:host=localhost;dbname=historical_database_name',  // Edit
			'emulatePrepare'=>true,
			'username'=>'historical_database_username',  // Edit
			'password'=>'historical_database_password',  // Edit
		),
	),
```
Also in main.php, in 'params':
```
	'params'=>array(
		// ...
		'historicalDbPrefix' => 'z',
	),
```

###Features

* historical-db provides most of what can be described as [Type 4 Slowly Changing Dimensions](http://en.wikipedia.org/wiki/Slowly_changing_dimension#Type_4).
* Each record stores the user_id that performed the action, action (INSERT, UPDATE, DELETE), and time of creation.
* Historical tables can be automatically created from your existing schema via subclass of CDbMigration.
* Mechanism for creating and maintaining your historical tables are provided in CDbMigration subclass.
* CActiveRecord has been subclassed with mechanisms to seamlessly create historical records.
* CDbCommand has been subclassed with new methods to handle INSERT, UPDATE, and DELETE with automatic historical record creation.
* All historical record creation occurs at the application layer.  historical-db assumes that you do not have any mechanisms at the database layer (such as triggers) to create historical database records.
* You are responsible for ensuring that the mechanisms in historical-db are not bypassed.  Doing so will break the integrity of your slowly changing dimensions.
* Subclass provided to support nested transactions in PDO.
* MySQL 5+ only at this point, hopefully generic in a future release.
* MySQL InnoDB and UTF-8 is assumed for all historical tables.

###Usage

* Create a historical table from an existing table: <code>HistoricalDbMigration::createHistoricalTable()</code>
* TODO: Finish docs for HistoricalDbMigration
* HistoricalActiveRecord has deactivated a few methods for now, rest will work seamlessly
* TODO: Finish docs for HistoricalActiveRecord
* HistoricalDbCommand now expects you to not use execute() for INSERT, UPDATE, DELETE.
* Please use the normal 'db' connection for creating historical records.  dbHistorical is primarly used internally for migrations and actually creating the historical records.
* Please instead use <code>HistoricalDbCommand::executeHistoricalInsert()</code>, <code>HistoricalDbCommand::executeHistoricalUpdate()</code>, <code>HistoricalDbCommand::executeHistoricalDelete()</code> instead of execute().
* <code>HistoricalDbCommand::insert()</code>, <code>HistoricalDbCommand::update()</code>, <code>HistoricalDbCommand::delete()</code> all support historical record creation.
* TODO: Finish docs for HistoricalDbCommand

###Known issues and other comments

* historical-db is very much a work in progress, and probably not yet fully functional/reliable in the current form.  USE AT YOUR OWN RISK!
* At this time, historical-db has many design warts and pitfalls for generic use; it was originally (quickly) designed and implemented for a specific project.
* Certain methods are quite slow; <code>HistoricalActiveRecord:updateAll()</code> for instance.  Certain methods contain unnecessary redundancies at this time.
* Goal is to refactor the code, clean it up, and make it reusable.
* Your contributions and/or comments/suggestions are greatly appreciated.

License
---------
Modified BSD License
[https://github.com/gtcode/historical-db](https://github.com/gtcode/historical-db)
