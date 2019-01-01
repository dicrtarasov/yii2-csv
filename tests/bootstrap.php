<?php
use yii\web\Application;

error_reporting(-1);
ini_set('display_errors', 1);

define('YII_ENABLE_ERROR_HANDLER', false);
define('YII_DEBUG', true);

$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_FILENAME'] = __FILE__;

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/../vendor/yiisoft/yii2/Yii.php');

new Application([
	'id' => 'testapp',
	'basePath' => __DIR__,
	'vendorPath' => dirname(__DIR__).'/vendor',
	'aliases' => [
		'@dicr/csv' => dirname(__DIR__) . '/src',
		'@dicr/tests' => dirname(__DIR__) . '/tests'
	],
	'components' => [
		'db' => [
			'class' => 'yii\db\Connection',
			'dsn' => 'sqlite::memory:',
		],
	],
]);
