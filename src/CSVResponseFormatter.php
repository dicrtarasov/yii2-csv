<?php
namespace dicr\csv;

use ArrayAccess;
use Traversable;
use yii\base\Arrayable;
use yii\base\Component;
use yii\base\Exception;
use yii\base\Model;
use yii\data\DataProviderInterface;
use yii\db\Query;
use yii\web\ResponseFormatterInterface;

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
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
class CSVResponseFormatter extends Component implements ResponseFormatterInterface
{
    /** @var string Content-Type текст */
    const CONTENT_TYPE_TEXT = 'text/csv';

    /** @var string Content-Type excel */
    const CONTENT_TYPE_EXCEL = 'application/vnd.ms-excel';

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
     * Запись строки в CSV
     *
     * @param array $line
     * @param resource $handle
     * @throws Exception
     * @return int bytes count
     */
    protected function writeLine(array $line, $handle)
    {
        if (empty($handle)) {
            throw new \InvalidArgumentException('handle');
        }

        if (! empty($this->charset) && $this->charset !== self::CHARSET_UTF8) {
            foreach ($line as $k => $v) {
                $line[$k] = iconv('utf-8', $this->charset . '//TRANSLIT', $v);
            }
        }

        $ret = @fputcsv($handle, $line, $this->delimiter, $this->enclosure, $this->escape);
        if ($ret === false) {
            $error = error_get_last();
            throw new Exception($error['message']);
        }

        return $ret;
    }

    /**
     * Конвертирует данные в Traversable
     *
     * @param array|object|\Traversable|Arrayable|Query|DataProviderInterface $data
     * @throws Exception
     * @return array|Traversable
     */
    protected static function convertData($data)
    {
        if (empty($data)) {
            return [];
        }

        if (is_array($data) || ($data instanceof Traversable)) {
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
     * @param array|false $fields
     *
     * @return array|ArrayAccess|Traversable массив значений
     */
    protected function convertRow($row)
    {
        if (empty($row)) {
            return [];
        }

        if (is_array($row) || ($row instanceof Traversable) || ($row instanceof \ArrayAccess)) {
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

            if (! empty($this->charset) && ! preg_match('~charset~uism', $this->contentType)) {

                $charset = $this->charset;

                if (preg_match('~cp1251~uism', $charset)) {
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
     * @param array|\Traversable|\yii\base\Arrayable|\yii\db\Query|\yii\data\DataProviderInterface $data данные
     * @return CSVFile
     */
    public function formatData($data)
    {
        // CSV-файл для вывода
        $csvFile = new CSVFile([
            'filename' => 'php://temp',
            'delimiter' => $this->delimiter,
            'escape' => $this->escape,
            'enclosure' => $this->enclosure,
            'charset' => $this->charset
        ]);

        if (! empty($data)) {

            // пишем заголовок
            if (! empty($this->fields)) {
                $csvFile->writeLine(array_values($this->fields));
            }

            foreach ($this->convertData($data) as $row) {
                if (is_callable($this->format)) {
                    $row = ($this->format)($row, $this);
                }

                $row = $this->convertRow($row);

                $line = [];

                if (!empty($this->fields)) { // если заданы заголовки, то выбираем только заданные поля в заданной последовательности
                    // проверяем доступность прямой выборки индекса из массива
                    if (!is_array($row) && !($row instanceof ArrayAccess)) {
                        throw new Exception('для использования списка полей fields необходимо чтобы элемент данных был либо array, либо типа ArrayAccess');
                    }

                    foreach (array_keys($this->fields) as $field) {
                        $line[] = $row[$field] ?? null;
                    }
                } else { // обходим все поля
                    // проверяем что данные доступны для обхода
                    if (!is_array($row) && !($row instanceof Traversable)) {
                        throw new Exception('элемент данных должен быть либо array, либо типа Traversable');
                    }

                    // обходим тип Traversable
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
     * {@inheritDoc}
     * @see \yii\web\ResponseFormatterInterface::format()
     */
    public function format($response = null)
    {
        if (empty($response)) {
            $response = \Yii::$app->response;
        }

        // пишем во временный CSVFile (php://temp)
        $csvFile = $this->formatData($response->data);

        $response->data = null;

        // заголовки загрузки файла
        $response->setDownloadHeaders($this->filename, $this->getMimeType(), false, ftell($csvFile->handle));

        // перематываем файл в начало
        $csvFile->reset();

        // устанавливаем поток для скачивания
        $response->stream = $csvFile->handle;

        return $response;
    }
}