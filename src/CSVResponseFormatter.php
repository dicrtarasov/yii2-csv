<?php
/**
 * @author Igor A Tarasov <develop@dicr.org>
 * @version 08.07.20 07:43:31
 */

declare(strict_types = 1);
namespace dicr\csv;

use ArrayAccess;
use Traversable;
use Yii;
use yii\base\Arrayable;
use yii\base\Component;
use yii\base\Exception;
use yii\base\Model;
use yii\data\DataProviderInterface;
use yii\db\Query;
use yii\web\Response;
use yii\web\ResponseFormatterInterface;
use function array_keys;
use function is_array;
use function is_callable;
use function is_iterable;
use function is_object;

/**
 * CSV File.
 *
 * Конвертирует данные из \yii\web\Response::data в CSV Response::stream, для возврата ответа в виде CSV-файла.
 * В Response::data можно устанавливать значения типа:
 * - null (пустой файл)
 * - array (обычный массив или ассоциативный, если установлены headers)
 * - object
 * - Traversable
 * - yii\base\Arrayable
 * - yii\db\Query
 * - yii\data\DataProviderInterface
 *
 *
 *
 * Для чтения нужно задать либо handle, либо filename. Если не задан handle, то открывается filename.
 * При записи, если не задан handle и filename, то handle открывается в php://temp.
 *
 * @property-read string|null $mimeType тип контента на основании contentType и charset
 * @deprecated перемещено в dicr/yii2-file
 */
class CSVResponseFormatter extends Component implements ResponseFormatterInterface
{
    /** @var string Content-Type текст */
    public const CONTENT_TYPE_TEXT = 'text/csv';

    /** @var string Content-Type excel */
    public const CONTENT_TYPE_EXCEL = 'application/vnd.ms-excel';

    /** @var string|null имя файла */
    public $filename;

    /** @var string|null Content-Type */
    public $contentType = self::CONTENT_TYPE_TEXT;

    /** @var string|null charset */
    public $charset = CSVFile::CHARSET_EXCEL;

    /** @var string|null разделитель полей */
    public $delimiter = CSVFile::DELIMITER_EXCEL;

    /** @var string|null ограничитель строк */
    public $enclosure = CSVFile::ENCLOSURE_DEFAULT;

    /** @var string|null экранирующий символ */
    public $escape = CSVFile::ESCAPE_DEFAULT;

    /**
     * @var array|null поля, ассоциативный массив в виде field => title
     *      null|false - не выводить
     *      true - определить заголовки автоматически
     *      array - заголовки колонок
     */
    public $fields = true;

    /** @var callable|null function($row, CSVResponseFormatter $formatter): array */
    public $format;

    /**
     * Конвертирует данные в Traversable
     *
     * @param array|object|Traversable|Arrayable|Query|DataProviderInterface $data
     * @return array|Traversable
     * @throws Exception
     */
    protected static function convertData($data)
    {
        if (empty($data)) {
            return [];
        }

        if (is_iterable($data)) {
            return $data;
        }

        if ($data instanceof Arrayable) {
            return $data->toArray();
        }

        if ($data instanceof Query) {
            return $data->each();
        }

        if ($data instanceof DataProviderInterface) {
            return $data->getModels();
        }

        if (is_object($data)) {
            return (array)$data;
        }

        throw new Exception('неизвестный тип в response->data');
    }

    /**
     * Конвертирует строку данных в массив значений
     *
     * @param array|object|Traversable|ArrayAccess|Arrayable|Model $row - данные строки
     * @return array|ArrayAccess|Traversable массив значений
     * @throws Exception
     */
    protected function convertRow($row)
    {
        if (empty($row)) {
            return [];
        }

        if (is_iterable($row) || ($row instanceof ArrayAccess)) {
            return $row;
        }

        if ($row instanceof Arrayable) {
            return $row->toArray();
        }

        if ($row instanceof Model) {
            return $row->attributes;
        }

        if (is_object($row)) {
            return (array)$row;
        }

        throw new Exception('unknown row format');
    }

    /**
     * Возвращает mime-тип контента
     *
     * @return string|null
     */
    public function getMimeType()
    {
        $mimeType = null;

        if (! empty($this->contentType)) {
            $mimeType = $this->contentType;
            if (! empty($this->charset) && stripos($this->contentType, 'charset') === false) {
                $charset = $this->charset;

                if (stripos($charset, 'cp1251') !== false) {
                    $charset = 'windows-1251';
                }

                $mimeType .= '; charset=' . $charset;
            }
        }

        return $mimeType;
    }

    /**
     * Форматирует ответ в CSV-файл
     *
     * @param array|Traversable|Arrayable|Query|DataProviderInterface $data данные
     * @return CSVFile
     * @throws Exception
     */
    public function formatData($data)
    {
        // CSV-файл для вывода
        $csvFile = new CSVFile([
            'filename' => 'php://temp',
            'delimiter' => $this->delimiter,
            'escape' => $this->escape,
            'enclosure' => $this->enclosure,
            'charset' => $this->charset,
        ]);

        if (! empty($data)) {
            // пишем заголовок
            if (! empty($this->fields)) {
                $csvFile->writeLine(array_values($this->fields));
            }

            foreach (self::convertData($data) as $row) {
                if (is_callable($this->format)) {
                    $row = ($this->format)($row, $this);
                }

                $row = $this->convertRow($row);

                $line = [];
                if (! empty($this->fields)) { // если заданы заголовки, то выбираем только заданные поля в заданной последовательности
                    // проверяем доступность прямой выборки индекса из массива
                    if (! is_array($row) && ! ($row instanceof ArrayAccess)) {
                        throw new Exception('для использования списка полей fields необходимо чтобы элемент данных был либо array, либо типа ArrayAccess');
                    }

                    $line = array_map(static function(string $field) use ($row) {
                        return $row[$field] ?? '';
                    }, array_keys($this->fields));
                } else { // обходим все поля
                    // проверяем что данные доступны для обхода
                    if (! is_array($row) && ! ($row instanceof Traversable)) {
                        throw new Exception('элемент данных должен быть либо array, либо типа Traversable');
                    }

                    // обходим тип iterable ВНИМАНИЕ !!! нельзя array_map
                    foreach ($row as $col) {
                        $line[] = $col;
                    }
                }

                $csvFile->writeLine($line);
            }
        }

        return $csvFile;
    }

    /**
     * @inheritDoc
     * @noinspection ClassMethodNameMatchesFieldNameInspection
     * @throws Exception
     */
    public function format($response = null)
    {
        if (empty($response)) {
            /** @var Response $response */
            /** @noinspection CallableParameterUseCaseInTypeContextInspection */
            $response = Yii::$app->response;
        }

        // пишем во временный CSVFile (php://temp)
        $csvFile = $this->formatData($response->data);
        $response->data = null;

        // заголовки загрузки файла
        $response->setDownloadHeaders(
            $this->filename,
            $this->getMimeType(),
            false,
            ftell($csvFile->handle)
        );

        // перематываем файл в начало
        $csvFile->reset();

        // устанавливаем поток для скачивания
        $response->stream = $csvFile->handle;

        return $response;
    }
}
