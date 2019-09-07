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
 * @property-read int|null $lineNo номер текущей строки
 * @property-read resource|null $handle указатель файла
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
class CSVFile extends BaseObject implements \Iterator
{
    /** @var string кодировка Excel */
    const CHARSET_EXCEL = 'cp1251';

    /** @var string кодировка по-умолчанию */
    const CHARSET_DEFAULT = 'utf-8';

    /** @var string кодировка для преобразования при чтении/записи */
    public $charset = self::CHARSET_DEFAULT;

    /** @var string разделитель полей по-умолчанию */
    const DELIMITER_DEFAULT = ',';

    /** @var string раздлитель полей Excel */
    const DELIMITER_EXCEL = ';';

    /** @var string разделитель полей */
    public $delimiter = self::DELIMITER_DEFAULT;

    /** @var string ограничиель полей по-умолчанию */
    const ENCLOSURE_DEFAULT = '"';

    /** @var string символ ограничения строк в полях */
    public $enclosure = self::ENCLOSURE_DEFAULT;

    /** @var string символ экранирования по-умолчанию */
    const ESCAPE_DEFAULT = '\\';

    /** @var string символ для экранирования */
    public $escape = self::ESCAPE_DEFAULT;

    /** @var string|null имя файла */
    public $filename;

    /** @var resource|array|null контекст файловых операций */
    public $context;

    /** @var resource|null файловый десриптор */
    protected $_handle;

    /** @var int|null текущий номер строки файла */
    protected $_lineNo;

    /** @var string[]|null текущие данные для Iterable */
    protected $_current = null;

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
            $this->charset = self::CHARSET_DEFAULT;
        }

        // контекст
        if (!is_resource($this->context)) {
            $this->context = stream_context_create($this->context);
        }
    }

    /**
     * Возвращает номер текущей строки
     *
     * @return int|NULL
     */
    public function getLineNo()
    {
        return $this->_lineNo;
    }

    /**
     * Возвращает указатель файла
     *
     * @return resource|NULL
     */
    public function getHandle()
    {
        return $this->_handle;
    }

    /**
     * Перематывает указатель в начальное состояние
     *
     * @throws Exception
     */
    public function reset()
    {
        error_clear_last();
        if (!empty($this->_handle) && @rewind($this->_handle) === false) {
            $err = error_get_last();
            throw new Exception('ошибка переметки файла: ' . $this->filename .': '. $err['message']);
        }

        $this->_lineNo = null;
        $this->_current = null;
    }

    /**
     * Декодирует строку из заданной кодировки
     *
     * @param array $line
     * @return string[]
     */
    protected function decode(array $line)
    {
        if ($this->charset != self::CHARSET_DEFAULT) { // конвертируем кодировку
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
        if (($this->charset != self::CHARSET_DEFAULT)) {
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
     * @return string[] текущую строку
     */
    public function readLine()
    {
        // открываем файл
        if (empty($this->_handle)) {
            if (! isset($this->filename)) {
                throw new InvalidConfigException('filename or handler');
            }

            $this->_handle = @fopen($this->filename, 'rt+', false, /** @scrutinizer ignore-type */ $this->context);
            if (empty($this->_handle)) {
                $err = error_get_last();
                @error_clear_last();
                throw new Exception('ошибка открытия файла: ' . $this->filename . ': ' . $err['message']);
            }

            $this->_lineNo = null;
        }

        // читаем строку
        @error_clear_last();
        $line = @fgetcsv($this->_handle, null, $this->delimiter, $this->enclosure, $this->escape);
        if ($line === false) {
            $err = error_get_last();
            @error_clear_last();
            if (isset($err)) {
                throw new Exception('ошибка чтения файла: ' . $this->filename . ': ' . $err['message']);
            }

            // конец файла
            $this->_current = null;
        } else {
            // счетчик текущей строки
            if (!isset($this->_lineNo)) {
                $this->_lineNo = 0;
            } else {
                $this->_lineNo ++;
            }

            // декодируем данные
            $this->_current = $this->decode($line);
        }

        return $this->_current;
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
        // запоминаем текущую строку
        $this->_current = $line;

        // открываем файл
        if (empty($this->_handle)) {
            if (! isset($this->filename)) {
                $this->filename = 'php://temp';
            }

            @error_clear_last();
            $this->_handle = @fopen($this->filename, 'wt+', false, /** @scrutinizer ignore-type */ $this->context);
            if (empty($this->_handle)) {
                $err = @error_get_last();
                throw new Exception('ошибка открытия файла: ' . $this->filename . ': ' . $err['message']);
            }
        }

        // кодируем данные
        $line = $this->encode($line);

        // пишем в файл
        @error_clear_last();
        $ret = @fputcsv($this->_handle, $line, $this->delimiter, $this->enclosure, $this->escape);
        if ($ret === false) {
            $err = error_get_last();
            throw new Exception('ошибка записи в файл: ' . $this->filename . ': ' . $err['message']);
        }

        // счетчик строк
        if (!isset($this->_lineNo)) {
            $this->_lineNo = 0;
        } else {
            $this->_lineNo ++;
        }

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
        $this->readLine();
    }

    /**
     * Возвращает номер текущей строки
     *
     * @return int номер строки, начиная с 1
     */
    public function key()
    {
        return $this->_lineNo;
    }

    /**
     * Возвращает текущую прочитанную строку
     *
     * @return string[]|null
     */
    public function current()
    {
        return $this->_current;
    }

    /**
     * Читает следующую строку
     */
    public function next()
    {
        $this->readLine();
    }

    /**
     * Проверяет корректность текущей позиции
     *
     * @return boolean
     */
    public function valid()
    {
        return $this->_current !== null;
    }

    /**
     * Деструктор
     */
    public function __destruct()
    {
        // нельзя закрывать файл, потому что он используется дальше после удаления этого объекта,
        // например в CSVResponseFormatter !!

        /*if (!empty($this->handle)) {
            @fclose($this->handle);
        }*/
    }
}
