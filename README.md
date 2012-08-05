historical-db
=============

Yii Components for managing historical database records

Introduction
---------
historical-db is a collection of components for [Yii](http://www.yiiframework.com/) that allow for automatic
creation of historical database records.  AR and DAO are both supported.

###Requirements

* Yii 1.1 or above
* PHP 5.2+

###Installation

* TODO

###Features

* historical-db provides most of what can be described as [Type 2 Slowly Changing Dimensions](http://en.wikipedia.org/wiki/Slowly_changing_dimension#Type_2)
* Each record stores the user_id that performed the action, action (INSERT, UPDATE, DELETE), and time of creation
* Historical tables can be automatically created from your existing schema via subclass of CDbMigration
* Mechanism for creating and maintaining your historical tables are provided in CDbMigration subclass
* CActiveRecord has been subclassed with mechanisms to seamlessly create historical records
* CDbCommand has been subclassed with new methods to handle INSERT, UPDATE, and DELETE with automatic historical record creation
* All historical record creation occurs at the application layer.  historical-db
assumes that you do not have any mechanisms at the database layer (such as
triggers) to create historical database records.
* You are responsible for ensuring that the mechanisms in historical-db are not
bypassed.  Doing so will break the integrity of your slowly changing dimensions

###Usage

* TODO

License
---------
Modified BSD License
[https://github.com/gtcode/Yii-Phpass](https://github.com/gtcode/Yii-Phpass)
