<?php
namespace dicr\test;

use PHPUnit\Framework\TestCase;
use dicr\csv\CSVResponseFormatter;
use dicr\csv\CSVFile;

/**
 * Test
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class CSVResponseFormatterTest extends TestCase
{
	/** @var array test data */
	protected static $testData = [];

	/**
	 * set up
	 */
	public static function setUpBeforeClass()
	{
		$test = new \stdClass();
		$test->name = 'Иванов Иван Иванович';
		$test->phone = '+79996341261';
		$test->comment = 'Проверка';

		self::$testData[] = $test;

		$test = new \stdClass();
		$test->name = "Имя\n";
		$test->phone = "\\;,-<>'";
		$test->comment = "\r\n\n ";

		self::$testData[] = $test;
	}

	/**
	 * Test response format
	 */
	public function testResponse()
	{
		$csvFormat = new CSVResponseFormatter([
			'contentType' => CSVResponseFormatter::CONTENT_TYPE_EXCEL,
			'filename' => 'test.csv',
		    'charset' => CSVFile::CHARSET_EXCEL,
			'delimiter' => CSVFile::DELIMITER_EXCEL,
		    'escape' => CSVFile::ESCAPE_DEFAULT,
			'headers' => ['Имя', 'Телефон', 'Комментарий'],
			'format' => function(\stdClass $data, $csvFormater) {
				return [
					$data->name,
					$data->phone,
					$data->comment
				];
			}
		]);

		$response = \Yii::$app->response;
		$response->data = self::$testData;
		$response = $csvFormat->format($response);

		self::assertEquals('attachment; filename="test.csv"', $response->headers->get('content-disposition'));
		self::assertEquals('application/vnd.ms-excel; charset=windows-1251', $response->headers->get('content-type'));
		self::assertEquals(93, $response->headers->get('content-length'));
		self::assertInternalType('resource', $response->stream);
		self::assertNull($response->data);
	}
}