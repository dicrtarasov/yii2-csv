<?php
namespace dicr\csv;

use yii\base\BaseObject;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Итератор текстовых файлов
 *
 * @property-read int $lineNo номер текущей строки
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class FileIterator extends BaseObject implements \Iterator
{
    /** @var string кодировка Windows */
    const CHARSET_WINDOWS = 'cp1251';

    /** @var string кодировка для перекодирования читаемых строк */
    public $charset;

    /** @var string полный путь файла */
    public $filename;

    /** @var array|resource контекст файла */
    public $_context;

    /** @var resource файловый указатель */
    protected $_handle;

    /** @var int|null номер текущей строки */
    protected $_lineNo;

    /** @var string текущая строка */
    protected $_line;

    /**
     * {@inheritDoc}
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
        if (empty($this->_context)) {
            $this->_context = [];
        }

        if (is_array($this->_context)) {
            $this->_context = stream_context_create($this->_context);
        }

        // файл
        $this->filename = trim($this->filename);
        if (empty($this->filename)) {
            throw new InvalidConfigException('filename');
        }

        // открываем файл
        $this->_handle = @fopen($this->filename, 'rt', false, $this->_context);
        if ($this->_handle === false) {
            $err = error_get_last();
            @error_clear_last();
            throw new Exception('ошибка открытия файла: ' . $this->filename . ': ' . $err['message']);
        }
    }

    /**
     * Читает строку из файла
     */
    protected function readLine()
    {
        $this->_line = fgets($this->_handle);
        if ($this->_line === false) {
            // конец файла
            $this->_line = null;
        } else {
            // увеличиваем счетчик строк
            if (isset($this->_lineNo)) {
                $this->_lineNo ++;
            } else {
                $this->_lineNo = 0;
            }

            // перекодируем данные
            if (!empty($this->charset)) {
                $this->_line = iconv($this->charset, 'utf-8//TRANSLIT', $this->_line);
            }
        }
    }

    /**
     * Отматывает указатель в начало
     *
     * @throws Exception
     */
    public function rewind()
    {
        if (@rewind($this->_handle) === false) {
            $err = error_get_last();
            error_clear_last();
            throw new Exception('ошибка перемотки: ' . $this->filename . ': ' . $err['message']);
        }

        // сбрасываем состояние
        $this->_lineNo = null;
        $this->_line = null;

        // читаем следующую строку
        $this->readLine();
    }

    /**
     * Возвращает номер текущей строки
     *
     * @return int|null номер строки, начиная с 0
     */
    public function key()
    {
        return $this->_lineNo;
    }

    /**
     * Возвращает текущую прочитанную строку
     *
     * @return string|null
     */
    public function current()
    {
        return $this->_line;
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
        return isset($this->_line);
    }

    /**
     * Деструктор
     */
    public function __destruct()
    {
        if (!empty($this->_handle)) {
            @fclose($this->_handle);
        }
    }
}
