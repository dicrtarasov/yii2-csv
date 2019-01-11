<?php
namespace dicr\csv;

use yii\base\BaseObject;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * CSV File.
 * При чтении если не указан открытый handle, то он открывается из filename.
 * При записи если не указан ни handle, ни filename, то в handle открывается в php://temp.
 *
 * @property-read int $lineNo номер текущей строки файла, начиная с 1
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
class CSVFile extends BaseObject implements \Iterator
{
    /** @var string|null кодировка для преобразования */
    public $charset;

    /** @var string разделитель полей */
    public $delimiter = ',';

    /** @var string символ ограничения строк в полях */
    public $enclosure = '"';

    /** @var string символ для экранирования */
    public $escape = "\\";

    /** @var string|null имя файла */
    public $filename;

    /** @var resource|null файловый десриптор */
    public $handle;

    /** @var int текущий номер строки файла */
    private $_lineNo = 0;

    /** @var mixed текущие данные для Iterable */
    private $current = null;

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();

        // если задана кодировка по-умолчанию utf-8, то удаляем значение
        $this->charset = trim($this->charset);
        if ($this->charset == '' || preg_match('~^utf\-?8$~uism', $this->charset)) {
            $this->charset = null;
        }
    }

    /**
     * Возвращает текущий номер строки
     *
     * @return int номер текущей сроки файла, начиная с 1
     */
    public function getLineNo()
    {
        return $this->_lineNo;
    }

    /**
     * Сбрасывает в начальное состояние
     *
     * @throws Exception
     */
    public function reset()
    {
        if (isset($this->handle)) {
            if (rewind($this->handle) === false) {
                $error = error_get_last();
                throw new Exception('ошибка отмотки: ' . $error['message']);
            }
        }
        $this->_lineNo = 0;
    }

    /**
     * Декодирует строку из заданной кодировки
     *
     * @param array $line
     * @return string[]
     */
    protected function decode(array $line)
    {
        if (isset($this->charset)) { // конвертируем кодировку
            foreach ($line as $key => $val) {
                $line[$key] = @iconv($this->charset, 'utf-8//TRANSLIT', (string) $val);
            }
        }
        return $line;
    }

    /**
     * Кодирует строку в заданую кодировку
     *
     * @param array $line
     * @return string[]
     */
    protected function encode(array $line)
    {
        if (isset($this->charset)) {
            $charset = $this->charset;
            if (strpos($charset, '//') === false) {
                $charset .= '//TRANSLIT';
            }
            foreach ($line as $key => $val) {
                $line[$key] = @iconv('utf-8', $charset, (string) $val);
            }
        }

        return $line;
    }

    /**
     * Читает сроку данных.
     * Если задан charset, то конвертирует кодировку.
     *
     * @throws \yii\base\Exception ошибка открытия файла
     * @return array|false
     */
    public function readLine()
    {
        // открываем файл
        if (empty($this->handle)) {
            if (! isset($this->filename)) {
                throw new InvalidConfigException('filename or handler');
            }

            $handle = @fopen($this->filename, 'rt+');

            if (is_resource($handle)) {
                $this->handle = $handle;
            } else {
                $error = error_get_last();
                throw new Exception($error['message']);
            }
        }

        // читаем строку
        $line = fgetcsv($this->handle, null, $this->delimiter, $this->enclosure, $this->escape);

        if ($line !== false) {
            $this->_lineNo ++;
            if (empty($line))
                $line = [];
            else
                $line = $this->decode($line);
        }

        return $line;
    }

    /**
     * Записывает массив данных в файл.
     * Если задан format, то вызывает для преобразования данных в массив.
     * Если задан charset, то кодирует в заданную кодировку.
     *
     * @param array $line
     * @throws \yii\base\Exception ошибка открытия/записи
     * @return int длина записанной строки
     */
    public function writeLine(array $line)
    {

        // открываем файл
        if (empty($this->handle)) {
            $filename = $this->filename;
            if (! isset($filename)) {
                $filename = 'php://temp';
            }

            $handle = @fopen($filename, 'wt+');
            if (is_resource($handle)) {
                $this->handle = $handle;
            } else {
                $error = error_get_last();
                throw new Exception($error['message']);
            }
        }

        $line = $this->encode($line);

        // пишем в файл
        $ret = fputcsv($this->handle, $line, $this->delimiter, $this->enclosure, $this->escape);
        if ($ret === false) {
            $error = error_get_last();
            throw new Exception($error['message']);
        }

        $this->_lineNo ++;

        return $ret;
    }

    // Интерфейс Iterable //////////////////////////////////////////////////////////////////

    /**
     * Отматывает указатель в начало
     *
     * @throws Exception
     */
    public function rewind()
    {
        $this->reset();
        $this->current = null;
        $this->next();
    }

    /**
     * Возвращает номер текущей строки
     *
     * @return int номер строки, начиная с 1
     */
    public function key()
    {
        return $this->getLineNo();
    }

    /**
     * Возвращает текущую прочитанную строку
     *
     * @return mixed|FALSE
     */
    public function current()
    {
        return $this->current;
    }

    /**
     * Читает следующую строку
     */
    public function next()
    {
        if ($this->current !== false) {
            $this->current = $this->readLine();
        }
    }

    /**
     * Проверяет корректность текущей позиции
     *
     * @return boolean
     */
    public function valid()
    {
        return $this->current !== false;
    }
}
