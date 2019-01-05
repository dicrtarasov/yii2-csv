<?php
namespace dicr\csv;

use yii\base\Arrayable;
use yii\base\Component;
use yii\base\Exception;
use yii\base\Model;
use yii\data\DataProviderInterface;
use yii\db\Query;
use yii\web\ResponseFormatterInterface;

/**
 * CSV File.
 * Для чтения нужно задать либо handle, либо filename. Если не задан handle, то открывается filename.
 * При записи, если не задан handle и filename, то handle открывается в php://temp.
 *
 * @property-read $mimeType тип контента на основании contentType и charset
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
class CSVResponseFormatter extends Component implements ResponseFormatterInterface
{
    /** @var string Content-Type текст */
    const CONTENT_TYPE_TEXT = 'text/csv';

    /** @var string Content-Type excel */
    const CONTENT_TYPE_EXCEL = 'application/vnd.ms-excel';

    /** @var string кодирова utf-8 */
    const CHARSET_UTF8 = 'utf-8';

    /** @var string кодировка Excel */
    const CHARSET_EXCEL = 'windows-1251';

    /** @var string стандартный разделитель полей - запятая */
    const DELIMITER_COMMA = ',';

    /** @var string разделитель полей Excel */
    const DELIMITER_EXCEL = ';';

    /** @var string|null имя файла */
    public $filename;

    /** @var string|null Content-Type */
    public $contentType = self::CONTENT_TYPE_TEXT;

    /** @var string|null charset */
    public $charset = self::CHARSET_UTF8;

    /** @var string|null разделитель полей */
    public $delimiter = self::DELIMITER_COMMA;

    /** @var string|null ограничитель строк */
    public $enclosure = '"';

    /** @var string|null экранирующий символ */
    public $escape = "\\";

    /**
     * @var array|null шапка
     *      null|false - не выводить
     *      array - заголовки колонок
     */
    public $headers = true;

    /** @var callable|null function($row, CSVResponseFormatter $formatter): array */
    public $format;

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();
    }

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
     * @param array|\Traversable|\yii\base\Arrayable|\yii\db\Query|\yii\data\DataProviderInterface $data
     * @throws Exception
     * @return \Traversable
     */
    protected static function convertData($data)
    {
        if (empty($data)) {
            return [];
        }

        if (is_array($data) || ($data instanceof \Traversable)) {
            return $data;
        }

        if ($data instanceof Arrayable) {
            /** @var Arrayable $data */
            return $data->toArray();
        }

        if ($data instanceof Query) {
            /** @var Query $data */
            return $data->each();
        }

        if ($data instanceof DataProviderInterface) {
            /** @var DataProviderInterface $data */
            return $data->getModels();
        }

        throw new Exception('неизвестный тип в response->data');
    }

    /**
     * Конвертирует строку данных в массив значений
     *
     * @param array|object|Model $row - массив
     *        - объект
     *        - Model
     * @param array|false $fields
     *
     * @return array массив значений
     */
    protected function convertRow($row)
    {
        if (empty($row)) {
            return [];
        }

        if (is_array($row) || ($row instanceof \Traversable)) {
            return $row;
        }

        if ($row instanceof Arrayable) {
            return $row->toArray();
        }

        if ($row instanceof Model) {
            return $row->attributes;
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
     * @return resource $handle
     */
    public function formatData($data)
    {

        // открываем файл
        $handle = fopen('php://temp', 'w+');
        if (empty($handle)) {
            throw new Exception('ошибка создания временного файла');
        }

        $first = true;

        if (! empty($data)) {
            if ($first) {
                if (! empty($this->headers)) {
                    $this->writeLine($this->headers, $handle);
                }
                $first = false;
            }

            foreach ($this->convertData($data) as $row) {
                if (is_callable($this->format)) {
                    $row = ($this->format)($row, $this);
                }

                if (! is_array($row)) {
                    $row = $this->convertRow($row);
                }

                $line = [];
                foreach ($row as $col) {
                    $line[] = $col;
                }

                $this->writeLine($line, $handle);
            }
        }

        return $handle;
    }

    /**
     * Форматирует Web-ответ в виде CSV-файла
     *
     * @param \yii\web\Response|null $response
     * @return \yii\web\Response
     */
    public function format($response = null)
    {
        if (empty($response)) {
            $response = \Yii::$app->response;
        }

        $handle = $this->formatData($response->data);

        $response->setDownloadHeaders($this->filename, $this->getMimeType(), false, ftell($handle));

        rewind($handle);
        $response->stream = $handle;
        $response->data = null;

        return $response;
    }
}