<?php
/**
 * Simple Active Record implementation for pearhunt
 * 
 * PHP version 5
 * 
 * @category  Utilities
 * @package   pearhunt
 * @author    Brett Bieber <brett.bieber@gmail.com>
 * @copyright 2011 Brett Bieber
 * @license   http://www1.unl.edu/wdn/wiki/Software_License BSD License
 * @link      http://pear2.php.net/
 */

/**
 * Simple active record implementation for pearhunt.
 * 
 * PHP version 5
 * 
 * @category  Utilities
 * @package   pearhunt
 * @author    Brett Bieber <brett.bieber@gmail.com>
 * @copyright 2011 Brett Bieber
 * @license   http://www1.unl.edu/wdn/wiki/Software_License BSD License
 * @link      http://pear2.php.net/
 */
class Record
{

    /**
     * Prepare the insert SQL for this record
     *
     * @param string &$sql The INSERT SQL query to prepare
     * 
     * @return array Associative array of field value pairs
     */
    protected function prepareInsertSQL(&$sql)
    {
        $sql    = 'INSERT INTO '.$this->getTable();
        $fields = get_object_vars($this);
        if (isset($fields['options'])) {
            unset($fields['options']);
        }
        $sql .= '(`'.implode('`,`', array_keys($fields)).'`)';
        $sql .= ' VALUES ('.str_repeat('?,', count($fields)-1).'?)';
        return $fields;
    }

    /**
     * Prepare the update SQL for this record
     * 
     * @param string &$sql The UPDATE SQL query to prepare
     *
     * @return array Associative array of field value pairs
     */
    protected function prepareUpdateSQL(&$sql)
    {
        $sql    = 'UPDATE '.$this->getTable().' ';
        $fields = get_class_vars(get_class($this));

        $sql .= 'SET `'.implode('`=?,`', array_keys($fields)).'`=? ';

        $sql .= 'WHERE ';
        foreach ($this->keys() as $key) {
            $sql .= $key.'=? AND ';
        }

        $sql = substr($sql, 0, -4);

        $fields = $fields + get_object_vars($this);
        return $fields;
    }

    /**
     * Get the primary keys for this table in the database
     *
     * @return array
     */
    function keys()
    {
        return array(
            'id',
        );
    }
    
    /**
     * Save the record. This automatically determines if insert or update
     * should be used, based on the primary keys.
     * 
     * @return bool
     */
    function save()
    {
        $key_set = true;

        foreach ($this->keys() as $key) {
            if (empty($this->$key)) {
                $key_set = false;
            }
        }

        if (!$key_set) {
            return $this->insert();
        }

        return $this->update();
    }

    /**
     * Insert a new record into the database
     *
     * @return bool
     */
    function insert()
    {
        $sql      = '';
        $fields   = $this->prepareInsertSQL($sql);
        $values   = array();
        $values[] = $this->getTypeString(array_keys($fields));
        foreach ($fields as $key=>$value) {
            $values[] =& $this->$key;
        }
        return $this->prepareAndExecute($sql, $values);
    }

    /**
     * Update this record in the database
     *
     * @return bool
     */
    function update()
    {
        $sql      = '';
        $fields   = $this->prepareUpdateSQL($sql);
        $values   = array();
        $values[] = $this->getTypeString(array_keys($fields));
        foreach ($fields as $key=>$value) {
            $values[] =& $this->$key;
        }
        // We're doing an update, so add in the keys!
        $values[0] .= $this->getTypeString($this->keys());
        foreach ($this->keys() as $key) {
            $values[] =& $this->$key;
        }
        return $this->prepareAndExecute($sql, $values);
    }

    /**
     * Prepare the SQL statement and execute the query
     * 
     * @param string $sql    The SQL query to execute
     * @param array  $values Values used in the query
     * 
     * @throws Exception
     * 
     * @return true
     */
    protected function prepareAndExecute($sql, $values)
    {
        $mysqli = self::getDB();
        if (!$stmt = $mysqli->prepare($sql)) {
            throw new RuntimeException(
                "Error preparing database statement! ($sql): $mysqli->error",
                500
            );
        }

        call_user_func_array(array($stmt, 'bind_param'), $values);
        if ($stmt->execute() === false) {
            throw new Exception($stmt->error, 500);
        }

        if ($mysqli->insert_id !== 0) {
            $this->id = $mysqli->insert_id;
        }

        return true;

    }

    /**
     * Get the type string used with prepared statements for the fields given
     *
     * @param array $fields Array of field names
     * 
     * @return string
     */
    function getTypeString($fields)
    {
        $types = '';
        foreach ($fields as $name) {
            switch($name) {
                case 'id':
                case 'package_id':
                case 'channel_id':
                    $types .= 'i';
                    break;
                default:
                    $types .= 's';
                    break;
            }
        }
        return $types;
    }

    /**
     * Convert the string given into a usable date for the RDBMS
     *
     * @param string $str A textual description of the date
     * 
     * @return string|false
     */
    function getDate($str)
    {
        if ($time = strtotime($str)) {
            return date('Y-m-d', $time);
        }

        if (strpos($str, '/') !== false) {
            list($month, $day, $year) = explode('/', $str);
            return $this->getDate($year.'-'.$month.'-'.$day);
        }
        // strtotime couldn't handle it
        return false;
    }

    /**
     * Simple method for getting a record by a single primary key
     *
     * @param string $table Table to retrieve record from
     * @param int    $id    The primary key/ID value
     * @param string $field The field that holds the primary key
     * 
     * @return false | Record
     */
    public static function getRecordByID($table, $id, $field = 'id')
    {
        $mysqli = self::getDB();
        $sql    = "SELECT * FROM $table WHERE $field = ".intval($id).' LIMIT 1;';
        if ($result = $mysqli->query($sql)) {
            return $result->fetch_assoc();
        }
        
        return false;
    }

    /**
     * Delete this record in the database
     *
     * @return bool
     */
    function delete()
    {
        $mysqli = self::getDB();
        $sql    = "DELETE FROM ".$this->getTable()." WHERE ";
        foreach ($this->keys() as $key) {
            if (empty($this->$key)) {
                throw new Exception('Cannot delete this record.' .
                                    'The primary key, '.$key.' is not set!',
                                    400);
            }
            $value = $this->$key;
            if ($this->getTypeString(array($key)) == 's') {
                $value = '"'.$mysqli->escape_string($value).'"';
            }
            $sql .= $key.'='.$value.' AND ';
        }
        $sql  = substr($sql, 0, -4);
        $sql .= ' LIMIT 1;';
        if ($result = $mysqli->query($sql)) {
            return true;
        }
        return false;
    }

    /**
     * Magic method for static calls
     *
     * @param string $method Method called
     * @param array  $args   Array of arguments passed to the method
     * 
     * @method getBy[FIELD NAME]
     * 
     * @throws Exception
     * 
     * @return mixed
     */
    public static function __callStatic($method, $args)
    {
        switch (true) {
        case preg_match('/getBy([\w]+)/', $method, $matches):
            $class    = get_called_class();
            $field    = strtolower($matches[1]);
            $whereAdd = null;
            if (isset($args[1])) {
                $whereAdd = $args[1];
            }
            return self::getByAnyField($class, $field, $args[0], $whereAdd);
            
        }
        throw new Exception('Invalid static method called.');
    }

    public static function getByAnyField($class, $field, $value, $whereAdd = '')
    {
        $record = new $class;

        if (!empty($whereAdd)) {
            $whereAdd = $whereAdd . ' AND ';
        }

        $mysqli = self::getDB();
        $sql    = 'SELECT * FROM '
                    . $record->getTable()
                    . ' WHERE '
                    . $whereAdd
                    . $field . ' = "' . $mysqli->escape_string($value) . '"';
        $result = $mysqli->query($sql);

        if ($result === false
            || $result->num_rows == 0) {
            return false;
        }

        $record->synchronizeWithArray($result->fetch_assoc());
        return $record;
    }

    /**
     * Connect to the database and return it
     *
     * @param boolean $useDb To use the db in the connection, or not.
     *
     * @return mysqli
     */
    public static function getDB($useDb = true)
    {
        if (!is_bool($useDb)) {
            throw InvalidArgumentException("Must be boolean.");
        }
        static $db = false;
        static $settings;

        if ($settings === null) {
            $settings = Config::getDbSettings();
        }

        if (!$db) {
            if ($useDb === true) {
                $db = new mysqli($settings['host'], $settings['user'], $settings['password'], $settings['dbname']);
            } else {
                $db = new mysqli($settings['host'], $settings['user'], $settings['password']);
            }
            if ($db->connect_error) {
                throw new RuntimeException('Connect Error (' . $db->connect_error . ')', $db->connect_errno);
            }
            $db->set_charset('utf8');
        } else {
            $db->select_db($settings['dbname']);
        }
        return $db;
    }

    /**
     * Synchronize member variables with the values in the array
     * 
     * @param array $data Associative array of field=>value pairs
     * 
     * @return void
     */
    function synchronizeWithArray($data)
    {
        foreach (get_object_vars($this) as $key=>$default_value) {
            if (isset($data[$key]) && !empty($data[$key])) {
                $this->$key = $data[$key];
            }
        }
    }

    /**
     * Reload data from the database and refresh member variables
     * 
     * @return void
     */
    function reload()
    {
        $record = self::getById($this->id);
        $this->synchronizeWithArray($record->toArray());
    }

    /**
     * Return an array representation of the object
     *
     * @return array
     */
    function toArray()
    {
        return get_object_vars($this);
    }
}
