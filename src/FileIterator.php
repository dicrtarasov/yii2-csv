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
    public $context;

    /** @var resource файловый указатель */
    protected $handle;

    /** @var int|null номер текущей строки */
    protected $lineNo;

    /** @var string текущая строка */
    protected $line;

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
        if (empty($this->context)) {
            $this->context = [];
        }

        if (is_array($this->context)) {
            $this->context = stream_context_create($this->context);
        }

        // файл
        $this->filename = trim($this->filename);
        if (empty($this->filename)) {
            throw new InvalidConfigException('filename');
        }

        // открываем файл
        $this->handle = @fopen($this->filename, 'rt', $this->context);
        if (empty($this->handle)) {
            $err = error_get_last();
            error_clear_last();
            throw new Exception('ошибка открытия файла: ' . $this->filename . ': ' . $err['message']);
        }
    }

    /**
     * Читает строку из файла
     */
    protected function readLine()
    {
        $this->line = fgets($this->handle);
        if ($this->line === false) {
            // конец файла
            $this->line = null;
        } else {
            // увеличиваем счетчик строк
            if (isset($this->lineNo)) {
                $this->lineNo ++;
            } else {
                $this->lineNo = 0;
            }

            // перекодируем данные
            if (!empty($this->charset)) {
                $this->line = iconv($this->charset, 'utf-8//TRANSLIT', $this->line);
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
        if (@rewind($this->handle) === false) {
            $err = error_get_last();
            error_clear_last();
            throw new Exception('ошибка перемотки: ' . $this->filename . ': ' . $err['message']);
        }

        // сбрасываем состояние
        $this->lineNo = null;
        $this->line = null;

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
        return $this->lineNo;
    }

    /**
     * Возвращает текущую прочитанную строку
     *
     * @return string|null
     */
    public function current()
    {
        return $this->line;
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
        return isset($this->line);
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
