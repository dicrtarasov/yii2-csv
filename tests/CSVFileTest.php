<?php
namespace dicr\test;

use PHPUnit\Framework\TestCase;
use dicr\csv\CSVFile;

/**
 * Test CSVFile
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
class CSVFileTest extends TestCase
{
	/** @var array тстовые данные */
	const TEST_DATA = [
		["Иван\r\nИванович", '+7(099)332-43-56', -1.1, 0, 1.12, '', "\n"],
		['Александр Васильевич', 0, '";,']
	];

	/**
	 * Тест
	 */
	public function testReadWrite()
	{
		$csvFile = new CSVFile([
			'charset' => 'cp1251',
		]);

		// записываем объекты в файл
		foreach (self::TEST_DATA as $line) {
			self::assertGreaterThan(0, $csvFile->writeLine($line));
		}

		// проверяем номер текущей строки
		self::assertEquals(1, $csvFile->lineNo);

		// сбрасваем
		$csvFile->reset();
		self::assertEquals(null, $csvFile->lineNo);

		// выбираем обратно через итерацию
		$data = [];
		foreach ($csvFile as $line) {
			$data[] = $line;
		}

		self::assertEquals(self::TEST_DATA, $data);
		self::assertEquals(null, $csvFile->current());
		self::assertEquals(1, $csvFile->lineNo);
	}
}
