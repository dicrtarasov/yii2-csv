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

    /** @var string разделитель полей по-умолчанию */
    const DELIMITER_DEFAULT = ',';

    /** @var string раздлитель полей Excel */
    const DELIMITER_EXCEL = ';';

    /** @var string ограничиель полей по-умолчанию */
    const ENCLOSURE_DEFAULT = '"';

    /** @var string символ экранирования по-умолчанию */
    const ESCAPE_DEFAULT = '\\';

    /** @var string|null кодировка для преобразования при чтении/записи */
    public $charset;

    /** @var string разделитель полей */
    public $delimiter = self::DELIMITER_DEFAULT;

    /** @var string символ ограничения строк в полях */
    public $enclosure = self::ENCLOSURE_DEFAULT;

    /** @var string символ для экранирования */
    public $escape = self::ESCAPE_DEFAULT;

    /** @var string|null имя файла */
    public $filename;

    /** @var resource|array|null контекст файловых операций */
    public $context;

    /** @var resource|null файловый десриптор */
    protected $handle;

    /** @var int|null текущий номер строки файла */
    protected $lineNo;

    /** @var string[]|null текущие данные для Iterable */
    protected $current = null;

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

        // контекст
        if (empty($this->context)) {
            $this->context = [];
        }

        if (is_array($this->context)) {
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
        return $this->lineNo;
    }

    /**
     * Возвращает указатель файла
     *
     * @return resource|NULL
     */
    public function getHandle()
    {
        return $this->handle;
    }

    /**
     * Перематывает указатель в начальное состояние
     *
     * @throws Exception
     */
    public function reset()
    {
        if (!empty($this->handle) && @rewind($this->handle) === false) {
            $err = error_get_last();
            error_clear_last();
            throw new Exception('ошибка переметки файла: ' . $this->filename .': '. $err['message']);
        }

        $this->lineNo = null;
        $this->current = null;
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
     * @return void
     */
    protected function readLine()
    {
        // открываем файл
        if (empty($this->handle)) {
            if (! isset($this->filename)) {
                throw new InvalidConfigException('filename or handler');
            }

            $this->handle = @fopen($this->filename, 'rt+', false, $this->context);
            if (empty($this->handle)) {
                $err = error_get_last();
                error_clear_last();
                throw new Exception('ошибка открытия файла: ' . $this->filename . ': ' . $err['message']);
            }

            $this->lineNo = null;
        }

        // читаем строку
        $line = @fgetcsv($this->handle, null, $this->delimiter, $this->enclosure, $this->escape);
        if ($line === false) {
            $err = error_get_last();
            error_clear_last();
            if (isset($err)) {
                throw new Exception('ошибка чтения файла: ' . $this->filename . ': ' . $err['message']);
            }

            // конец файла
            $this->current = null;
        } else {
            // счетчик текущей строки
            if (!isset($this->lineNo)) {
                $this->lineNo = 0;
            } else {
                $this->lineNo ++;
            }

            // декодируем данные
            $this->current = $this->decode($line);
        }
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
        $this->current = $line;

        // открываем файл
        if (empty($this->handle)) {
            if (! isset($this->filename)) {
                $this->filename = 'php://temp';
            }

            $this->handle = @fopen($this->filename, 'wt+', false, $this->context);
            if (empty($this->handle)) {
                $err = error_get_last();
                error_clear_last();
                throw new Exception('ошибка открытия файла: ' . $this->filename . ': ' . $err['message']);
            }
        }

        // кодируем данные
        $line = $this->encode($line);

        // пишем в файл
        $ret = @fputcsv($this->handle, $line, $this->delimiter, $this->enclosure, $this->escape);
        if ($ret === false) {
            $err = error_get_last();
            error_clear_last();
            throw new Exception('ошибка записи в файл: ' . $this->filename . ': ' . $err['message']);
        }

        // счетчик строк
        if (!isset($this->lineNo)) {
            $this->lineNo = 0;
        } else {
            $this->lineNo ++;
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
        return $this->lineNo;
    }

    /**
     * Возвращает текущую прочитанную строку
     *
     * @return string[]|null
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
        $this->readLine();
    }

    /**
     * Проверяет корректность текущей позиции
     *
     * @return boolean
     */
    public function valid()
    {
        return $this->current !== null;
    }

    /**
     * Деструктор
     */
    public function __destruct()
    {
        if (!empty($this->handle)) {
            @fclose($this->handle);
        }
    }
}
