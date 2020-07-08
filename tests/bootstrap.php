<?php
/**
 * @author Igor A Tarasov <develop@dicr.org>
 * @version 08.07.20 07:42:57
 */

/** @noinspection PhpUnused */
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types = 1);

use yii\web\Application;

define('YII_ENV', 'dev');
define('YII_DEBUG', true);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

new Application([
    'id' => 'test',
    'basePath' => __DIR__
]);
