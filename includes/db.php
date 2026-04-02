<?php
/**
 * Подключение к базе данных и работа с ней
 * Использует PDO для безопасного взаимодействия с БД
 * 
 * @version 1.0
 * @author Smart Smoker Team
 */

// Запрет прямого доступа
if (!defined('SMART_SMOKER')) {
    exit('Доступ запрещен');
}

/**
 * Класс для работы с базой данных
 */
class Database {
    /**
     * @var PDO|null Экземпляр подключения к БД
     */
    private static $instance = null;
    
    /**
     * @var PDO|null Текущее соединение
     */
    private $connection = null;
    
    /**
     * Защищенный конструктор (синглтон)
     */
    private function __construct() {
        $this->connect();
    }
    
    /**
     * Защищенный клон (синглтон)
     */
    private function __clone() {}
    
    /**
     * Защищенный десериализация (синглтон)
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
    
    /**
     * Получить экземпляр класса (синглтон)
     * 
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Подключение к базе данных
     * 
     * @throws Exception При ошибке подключения
     */
    private function connect() {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false, // Отключаем для безопасности
            ];
            
            // Add MySQL-specific options only if extension is loaded
            if (extension_loaded('pdo_mysql')) {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES " . DB_CHARSET;
            }
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // Логирование успешного подключения
            $this->log('Database connection established');
            
        } catch (PDOException $e) {
            // Логирование ошибки
            $this->log('Database connection failed: ' . $e->getMessage(), 'ERROR');
            
            throw new Exception('Ошибка подключения к базе данных: ' . $e->getMessage());
        }
    }
    
    /**
     * Получить соединение с БД
     * 
     * @return PDO
     */
    public function getConnection() {
        if ($this->connection === null) {
            $this->connect();
        }
        
        return $this->connection;
    }
    
    /**
     * Выполнить запрос с подготовленными параметрами
     * 
     * @param string $sql SQL-запрос
     * @param array $params Параметры для подготовленного запроса
     * @return PDOStatement
     * @throws Exception При ошибке выполнения запроса
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            
            return $stmt;
            
        } catch (PDOException $e) {
            $this->log('Query failed: ' . $e->getMessage() . ' | SQL: ' . $sql, 'ERROR');
            throw new Exception('Ошибка выполнения запроса: ' . $e->getMessage());
        }
    }
    
    /**
     * Выполнить запрос и вернуть все результаты
     * 
     * @param string $sql SQL-запрос
     * @param array $params Параметры для подготовленного запроса
     * @return array
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Выполнить запрос и вернуть одну строку
     * 
     * @param string $sql SQL-запрос
     * @param array $params Параметры для подготовленного запроса
     * @return array|false
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * Выполнить запрос и вернуть одно значение
     * 
     * @param string $sql SQL-запрос
     * @param array $params Параметры для подготовленного запроса
     * @return mixed
     */
    public function fetchColumn($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
    }
    
    /**
     * Валидация имени идентификатора (таблица, колонка)
     * Защита от SQL-инъекции через имена полей
     *
     * @param string $name
     * @return string Имя в backticks
     * @throws Exception При недопустимом имени
     */
    private function validateIdentifier(string $name): string {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new Exception("Недопустимое имя идентификатора: $name");
        }
        return '`' . $name . '`';
    }

    /**
     * Вставить запись в таблицу
     * 
     * @param string $table Имя таблицы
     * @param array $data Ассоциативный массив данных
     * @return int ID вставленной записи
     */
    public function insert($table, $data) {
        $safeTable = $this->validateIdentifier($table);
        $safeFields = array_map([$this, 'validateIdentifier'], array_keys($data));
        $placeholders = array_fill(0, count($safeFields), '?');
        
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $safeTable,
            implode(', ', $safeFields),
            implode(', ', $placeholders)
        );
        
        $stmt = $this->query($sql, array_values($data));
        
        return $this->connection->lastInsertId();
    }
    
    /**
     * Обновить запись в таблице
     * 
     * @param string $table Имя таблицы
     * @param array $data Ассоциативный массив данных
     * @param string $where Условие WHERE
     * @param array $whereParams Параметры для условия WHERE
     * @return int Количество обновленных строк
     */
    public function update($table, $data, $where, $whereParams = []) {
        $safeTable = $this->validateIdentifier($table);
        $setParts = [];
        $params = [];
        
        foreach ($data as $field => $value) {
            $setParts[] = $this->validateIdentifier($field) . ' = ?';
            $params[] = $value;
        }
        
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $safeTable,
            implode(', ', $setParts),
            $where
        );
        
        $params = array_merge($params, $whereParams);
        $stmt = $this->query($sql, $params);
        
        return $stmt->rowCount();
    }
    
    /**
     * Удалить записи из таблицы
     * 
     * @param string $table Имя таблицы
     * @param string $where Условие WHERE
     * @param array $params Параметры для условия WHERE
     * @return int Количество удаленных строк
     */
    public function delete($table, $where, $params = []) {
        $sql = sprintf('DELETE FROM %s WHERE %s', $table, $where);
        $stmt = $this->query($sql, $params);
        
        return $stmt->rowCount();
    }
    
    /**
     * Начать транзакцию
     */
    public function beginTransaction() {
        $this->connection->beginTransaction();
    }
    
    /**
     * Зафиксировать транзакцию
     */
    public function commit() {
        $this->connection->commit();
    }
    
    /**
     * Откатить транзакцию
     */
    public function rollback() {
        $this->connection->rollBack();
    }
    
    /**
     * Проверить, находится ли соединение в транзакции
     * 
     * @return bool
     */
    public function inTransaction() {
        return $this->connection->inTransaction();
    }
    
    /**
     * Логирование операций с БД
     * 
     * @param string $message Сообщение
     * @param string $level Уровень логирования
     */
    private function log($message, $level = 'INFO') {
        // Используем глобальную функцию логирования, если она доступна
        if (function_exists('logMessage')) {
            logMessage($level, $message, 'DATABASE');
        }
    }
    
    /**
     * Получить информацию о соединении
     * 
     * @return array
     */
    public function getConnectionInfo() {
        return [
            'host' => DB_HOST,
            'database' => DB_NAME,
            'charset' => DB_CHARSET,
            'connected' => $this->connection !== null
        ];
    }
    
    /**
     * Закрыть соединение с БД
     */
    public function close() {
        $this->connection = null;
        self::$instance = null;
    }
}

/**
 * Удобная функция для получения экземпляра БД
 * 
 * @return Database
 */
function db() {
    return Database::getInstance();
}

// =====================================================
// Завершение файла
// =====================================================