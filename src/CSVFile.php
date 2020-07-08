<?php
/**
 * @author Igor A Tarasov <develop@dicr.org>
 * @version 08.07.20 07:43:33
 */

declare(strict_types = 1);
namespace dicr\csv;

use Iterator;
use yii\base\BaseObject;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use function is_resource;

/**
 * CSV File.
 * При чтении если не указан открытый handle, то он открывается из filename.
 * При записи если не указан ни handle, ни filename, то в handle открывается в php://temp.
 *
 * @property-read int|null $lineNo номер текущей строки
 * @property-read resource|null $handle указатель файла
 */
class CSVFile extends BaseObject implements Iterator
{
    /** @var string кодировка Excel */
    public const CHARSET_EXCEL = 'cp1251';

    /** @var string кодировка по-умолчанию */
    public const CHARSET_DEFAULT = 'utf-8';

    /** @var string кодировка для преобразования при чтении/записи */
    public $charset = self::CHARSET_DEFAULT;

    /** @var string разделитель полей по-умолчанию */
    public const DELIMITER_DEFAULT = ',';

    /** @var string разделитель полей Excel */
    public const DELIMITER_EXCEL = ';';

    /** @var string разделитель полей */
    public $delimiter = self::DELIMITER_DEFAULT;

    /** @var string ограничитель полей по-умолчанию */
    public const ENCLOSURE_DEFAULT = '"';

    /** @var string символ ограничения строк в полях */
    public $enclosure = self::ENCLOSURE_DEFAULT;

    /** @var string символ экранирования по-умолчанию */
    public const ESCAPE_DEFAULT = '\\';

    /** @var string символ для экранирования */
    public $escape = self::ESCAPE_DEFAULT;

    /** @var string|null имя файла */
    public $filename;

    /** @var resource|array|null контекст файловых операций */
    public $context;

    /** @var resource|null файловый дескриптор */
    protected $_handle;

    /** @var int|null текущий номер строки файла */
    protected $_lineNo;

    /** @var string[]|null текущие данные для Iterable */
    protected $_current;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        // если задана кодировка по-умолчанию utf-8, то удаляем значение
        $this->charset = trim($this->charset);
        if ($this->charset === '' || preg_match('~^utf\-?8$~uim', $this->charset)) {
            $this->charset = self::CHARSET_DEFAULT;
        }

        // контекст
        if (! is_resource($this->context)) {
            $this->context = stream_context_create($this->context);
        }
    }

    /**
     * Возвращает номер текущей строки
     *
     * @return int|null
     */
    public function getLineNo()
    {
        return $this->_lineNo;
    }

    /**
     * Возвращает указатель файла
     *
     * @return resource|null
     */
    public function getHandle()
    {
        return $this->_handle;
    }

    /**
     * Перематывает указатель в начальное состояние.
     *
     * @throws Exception
     * @noinspection PhpUsageOfSilenceOperatorInspection
     */
    public function reset()
    {
        if (! empty($this->_handle) && @rewind($this->_handle) === false) {
            $err = @error_get_last();
            throw new Exception('ошибка переметки файла: ' . $this->filename . ': ' . $err['message']);
        }

        $this->_lineNo = null;
        $this->_current = null;
    }

    /**
     * Декодирует строку из заданной кодировки.
     *
     * @param array $line
     * @return string[]
     * @noinspection PhpUsageOfSilenceOperatorInspection
     */
    protected function decode(array $line)
    {
        if ($this->charset !== self::CHARSET_DEFAULT) {
            $line = array_map(function($val) {
                return @iconv($this->charset, 'utf-8//TRANSLIT', (string)$val);
            }, $line);
        }

        return $line;
    }

    /**
     * Кодирует строку в заданную кодировку
     *
     * @param array $line
     * @return string[]
     * @noinspection PhpUsageOfSilenceOperatorInspection
     */
    protected function encode(array $line)
    {
        if (($this->charset !== self::CHARSET_DEFAULT)) {
            $charset = $this->charset;
            if (strpos($charset, '//') === false) {
                $charset .= '//TRANSLIT';
            }

            $line = array_map(static function($val) use ($charset) {
                return @iconv('utf-8', $charset, (string)$val);
            }, $line);
        }

        return $line;
    }

    /**
     * Читает сроку данных.
     * Если задан charset, то конвертирует кодировку.
     *
     * @return string[] текущую строку
     * @throws Exception ошибка открытия файла
     * @noinspection PhpUsageOfSilenceOperatorInspection
     */
    public function readLine()
    {
        // открываем файл
        if (empty($this->_handle)) {
            if (! isset($this->filename)) {
                throw new InvalidConfigException('filename or handler');
            }

            $this->_handle = @fopen($this->filename, 'rt+', false, /** @scrutinizer ignore-type */ $this->context);
            /** @noinspection NotOptimalIfConditionsInspection */
            if (empty($this->_handle)) {
                $err = error_get_last();
                @error_clear_last();
                throw new Exception('ошибка открытия файла: ' . $this->filename . ': ' . $err['message']);
            }

            $this->_lineNo = null;
        }

        // перед чтением строки сбрасываем ошибку чтобы отличат конец файла от ошибки
        @error_clear_last();

        // читаем строку
        $line = @fgetcsv($this->_handle, null, $this->delimiter, $this->enclosure, $this->escape);

        if ($line !== false) {
            // счетчик текущей строки
            if (isset($this->_lineNo)) {
                $this->_lineNo ++;
            } else {
                $this->_lineNo = 0;
            }

            // декодируем данные
            $this->_current = $this->decode($line);
        } else {
            // проверяем была ли ошибка
            $err = error_get_last();
            if (isset($err)) {
                // в случае ошибки выбрасываем исключение
                @error_clear_last();
                throw new Exception('ошибка чтения файла: ' . $this->filename . ': ' . $err['message']);
            }

            // принимаем как конец файла
            $this->_current = null;
        }

        return $this->_current;
    }

    /**
     * Записывает массив данных в файл.
     * Если задан format, то вызывает для преобразования данных в массив.
     * Если задан charset, то кодирует в заданную кодировку.
     *
     * @param array $line
     * @return int длина записанной строки
     * @throws Exception ошибка открытия/записи
     * @noinspection PhpUsageOfSilenceOperatorInspection
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

            $this->_handle = @fopen($this->filename, 'wt+', false, /** @scrutinizer ignore-type */ $this->context);
            if ($this->_handle === false) {
                $err = @error_get_last();
                @error_clear_last();
                throw new Exception('ошибка открытия файла: ' . $this->filename . ': ' . $err['message']);
            }
        }

        // кодируем данные
        $line = $this->encode($line);

        // пишем в файл
        $ret = @fputcsv($this->_handle, $line, $this->delimiter, $this->enclosure, $this->escape);
        if ($ret === false) {
            $err = error_get_last();
            @error_clear_last();
            throw new Exception('ошибка записи в файл: ' . $this->filename . ': ' . $err['message']);
        }

        // счетчик строк
        if (isset($this->_lineNo)) {
            $this->_lineNo ++;
        } else {
            $this->_lineNo = 0;
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
     *
     * @throws Exception
     */
    public function next()
    {
        $this->readLine();
    }

    /**
     * Проверяет корректность текущей позиции
     *
     * @return bool
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
