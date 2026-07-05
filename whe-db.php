<?php
class wheDB {
    public $logging         = false;
    public $log             = [];
    public $error           = null;
    private $charset;
    private $mysqli;
    private $cache          = [];

    public function __construct($host, $username, $password, $dbname, $charset = 'utf8mb4') {
        $this->charset      = $charset;
        $this->mysqli       = new mysqli($host, $username, $password, $dbname);
        $this->mysqli->set_charset($charset);
        return $this->mysqli;
    }

    // === GENERAL ===

    public function query(string $sql) {
        $sql = trim($sql);
        if ($this->logging) {
            $log_sql = $sql;
            $log_sql = preg_replace('/\s+/', ' ', $log_sql);
            $this->log[$log_sql]++;
        }
        $query  = $this->mysqli->prepare($sql);
        if ($this->mysqli->error_list) {
            if ($this->mysqli->error_list[0]['errno'] == 1064 && mb_stripos($sql,';') > 0) {
                return $this->multi_query($sql);
            } else {
                if ($this->mysqli->error_list) {
                    print_r($this->mysqli->error_list);
                }
                throw new Exception('SQL Error');
            }
        }
        if (is_object($query)) {
            $query->execute();
            if ($query->error) {
                $this->error = $query->error;
            }
            $result = $query->get_result();
            if ($result == false && $this->mysqli->error_list) {
                _pa($this->mysqli->error_list);
            }
        } else {
            $result = false;
        }
        return $result;
    }

    public function multi_query($sql) {
        $sql = trim($sql);
        if ($this->logging) {
            $log_sql = $sql;
            $log_sql = str_ireplace("\n"," ",$log_sql);
            $log_sql = str_ireplace("\t"," ",$log_sql);
            $log_sql = str_ireplace("\r"," ",$log_sql);
            $this->log[$log_sql]++;
        }
        // Multi-Query
        $this->mysqli->begin_transaction();
        $result = $this->mysqli->multi_query($sql);
        if ($result) {
            do {
                $this->mysqli->use_result();
            } while ($this->mysqli->next_result());
        }
        if ($this->mysqli->errno) {
            if ($this->mysqli->error_list) {
                print_r($this->mysqli->error_list);
            }
            $this->mysqli->rollback();
            throw new Exception('SQL Error');
        } else {
            $this->mysqli->commit();
            return true;
        }
    }

    public function quoteIdentifier($name) {
        return "`$name`";
    }

    private function getBindType($value) {
        $type = 's';
        if (is_float($value)) {
            $type   = 'd';
        } else if (is_int($value)) {
            $type   = 'i';
        }
        return $type;
    }

    public function closeConnection() {
        return $this->mysqli->close();
    }

    // === FETCH ===

    public function fetchAll(string $sql, bool $cached = false) {
        if ($cached) {
            $cacheKey = 'fetchAll-'.md5($sql);
            if (isset($this->cache[$cacheKey])) {
                return $this->cache[$cacheKey];
            }
        }
        $rows   = $this->query($sql, $cached);
        if ($rows !== false) {
            $result = $rows->fetch_all(MYSQLI_ASSOC);
        } else {
            $result = [];
        }
        if ($cached) {
            $this->cache[$cacheKey] = $result;
        }
        if (is_array($result)) {
            return $result;
        } else {
            return [];
        }
    }

    public function fetchRow(string $sql, bool $cached = false) {
        if ($cached) {
            $cacheKey = 'fetchRow-'.md5($sql);
            if (isset($this->cache[$cacheKey])) {
                return $this->cache[$cacheKey];
            }
        }
        $rows   = $this->query($sql, $cached);
        if ($rows !== false) {
            $result = $rows->fetch_assoc();
        } else {
            $result = [];
        }
        if ($cached) {
            $this->cache[$cacheKey] = $result;
        }
        if (is_array($result)) {
            return $result;
        } else {
            return [];
        }
    }

    public function fetchCol(string $sql, bool $cached = false) {
        if ($cached) {
            $cacheKey = 'fetchCol-'.md5($sql);
            if (isset($this->cache[$cacheKey])) {
                return $this->cache[$cacheKey];
            }
        }
        $rows   = $this->query($sql, $cached);
        $result = [];
        if (is_iterable($rows)) {
            foreach ($rows as $row) {
                $result[] = array_shift($row);
            }
            if ($cached) {
                $this->cache[$cacheKey] = $result;
            }
        }
        if (is_array($result)) {
            return $result;
        } else {
            return [];
        }
    }

    public function fetchAssoc(string $sql, bool $cached = false) {
        if ($cached) {
            $cacheKey = 'fetchAssoc-'.md5($sql);
            if (isset($this->cache[$cacheKey])) {
                return $this->cache[$cacheKey];
            }
        }
        $rows   = $this->query($sql, $cached);
        $result = [];
        if (is_iterable($rows)) {
            foreach ($rows as $row) {
                $result[$row[array_key_first($row)]] = $row;
            }
            if ($cached) {
                $this->cache[$cacheKey] = $result;
            }
            return $result;
        } else {
            return [];
        }
    }

    public function fetchOne(string $sql, bool $cached = false) {
        if ($cached) {
            $cacheKey = 'fetchOne-'.md5($sql);
            if (isset($this->cache[$cacheKey])) {
                return $this->cache[$cacheKey];
            }
        }
        $row    = $this->fetchCol($sql, $cached);
        $result = array_shift($row);
        if ($cached) {
            $this->cache[$cacheKey] = $result;
        }
        return $result;
    }

    public function fetchPairs(string $sql, bool $cached = false) {
        if ($cached) {
            $cacheKey = 'fetchPairs-'.md5($sql);
            if (isset($this->cache[$cacheKey])) {
                return $this->cache[$cacheKey];
            }
        }
        $rows   = $this->fetchAll($sql, $cached);
        $pairs  = [];
        if (is_iterable($rows)) {
            foreach ($rows as $row) {
                $keys   = array_keys($row);
                $pairs[$row[$keys[0]]]  = $row[$keys[1]];
            }
            if ($cached) {
                $this->cache[$cacheKey] = $pairs;
            }
        }
        if (is_array($pairs)) {
            return $pairs;
        } else {
            return [];
        }
    }

    // === INSERT ===

    public function insert($table, array $data) {
        return $this->into('INSERT',$table,$data);
    }

    public function insertIgnore($table,array $data) {
        return $this->into('INSERT IGNORE',$table,$data);
    }

    public function replace($table, array $data) {
        return $this->into('REPLACE',$table,$data);
    }

    private function into($function, $table,array $data) {
        $table_desc     = $this->describeTable($table);
        $table_quoted   = $this->quoteIdentifier($table);
        $sql_arr[]      = "$function INTO $table_quoted";
        $types          = [];
        $columns        = [];
        $values         = [];
        $placeholders   = [];
        foreach ($data as $key=>$value) {
            if (isset($table_desc[$key]) && !$table_desc[$key]['GENERATED'] && (!$table_desc[$key]['AUTO_INCREMENT'] || $function == 'REPLACE')) {
                if ($value === null && !$table_desc[$key]['NULLABLE']) {
                    continue; // skip non-nullable null fields
                }
                if (is_object($value) && method_exists($value, '__toString')) {
                    $value  = strval($value);
                } else if (is_array($value) || is_object($value)) {
                    $value  = json_encode($value);
                }
                $types[]        = $this->getBindType($value);
                $columns[]      = $this->quoteIdentifier($key);
                $values[]       = $value;
                $placeholders[] = '?';
            }
        }
        $sql_arr[] = '(' . implode(',',$columns) . ')';
        $sql_arr[] = 'VALUES (' . implode(',',$placeholders) . ')';
        $sql = implode(' ',$sql_arr);
        $query = $this->mysqli->prepare($sql);
        $query->bind_param(implode('',$types), ...$values);
        $query->execute();
        if ($query->error) {
            $this->error = $query->error;
        }
        return intval($query->affected_rows);
    }

    public function lastInsertId() {
        return $this->mysqli->insert_id;
    }

    // === UPDATE ===

    public function update(string $table,array $data,string $where = '') {
        return $this->set('UPDATE',$table,$data,$where);
    }

    public function updateIgnore(string $table, array $data, string $where = '') {
        return $this->set('UPDATE IGNORE',$table,$data,$where);
    }

    private function set(string $function,string $table,array $data,string $where = '') {
        $sql_arr    = [];
        $set_keys   = [];
        $set_values = [];
        $set_types  = [];
        $sql_arr[]  = $function;
        $sql_arr[]  = $this->quoteIdentifier($table);
        $sql_arr[]  = 'SET';
        foreach ($data as $key=>$value) {
            $set_keys[]     = $this->quoteIdentifier($key) . ' = ?';
            $set_values[]   = (is_array($value) || is_object($value)) ? json_encode($value) : $value;
            $set_types[]    = $this->getBindType($value);
        }
        $sql_arr[]  = implode(',',$set_keys);
        if ($where) {
            $sql_arr[]  = "WHERE $where";
        }
        $sql        = implode(' ',$sql_arr);
        $query      = $this->mysqli->prepare($sql);
        $query->bind_param(implode('',$set_types), ...$set_values);
        $success    = $query->execute();
        if ($query->error) {
            $this->error = $query->error;
        }
        if ($success) {
            $affected_rows = $query->affected_rows;
            if ($affected_rows > 0) {
                return $affected_rows;
            } else {
                return true;
            }
        } else {
            return false;
        }
    }

    // === DELETE ===

    public function delete(string $table, string $where = '') {
        $sql = 'DELETE FROM ' . $this->quoteIdentifier($table);
        if ($where) {
            $sql .= ' WHERE ' . $where;
        }
        return (int) $this->mysqli->query($sql);
    }

    // === UTILITY ===

    function queryToCsv($query, $filename, $output = false, $headers = true) {
        if($output) {
            // send response headers to the browser
            $filename_parts = explode('/',$filename);
            header( 'Content-Type: text/csv' );
            header( 'Content-Disposition: attachment;filename='.end($filename_parts));
            $fp = fopen('php://output', 'w');
        } else {
            $fp = fopen(abs_path($filename), 'w');
        }
        $result = $this->mysqli->query($query);
        if($headers) {
            // output header row (if at least one row exists)
            $row = $result->fetch_assoc();
            if($row) {
                fputcsv($fp, array_keys($row));
                // reset pointer back to beginning
                $result->data_seek(0);
            }
        }
        while ($row = $result->fetch_assoc()) {
            fputcsv($fp, $row);
        }
        fclose($fp);
    }

    public function countRows($table,$condition = FALSE) {
        $sql = "SELECT COUNT(*) FROM " . $this->quoteIdentifier($table);
        if ($condition)
            $sql .= " WHERE $condition";
        return $this->fetchOne($sql);
    }

    public function describeTable($table, $memory_cached = true) {
        $rows       = $this->fetchAll('DESCRIBE ' . $this->quoteIdentifier($table));
        $tableData  = [];
        $i          =   1;
        if ($memory_cached && $this->cache['db_describeTable_'.$table]) {
            return $this->cache['db_describeTable_'.$table];
        }
        if (is_iterable($rows)) {
            foreach ($rows as $row) {
                preg_match_all('/[(]([0-9]+)[)]/',$row['Type'],$length_matches);
                $typeArr = explode('(',$row['Type']);
                $tableData[$row['Field']] = [
                    'TABLE_NAME'        => $table,
                    'COLUMN_NAME'       => $row['Field'],
                    'COLUMN_POSITION'   => $i,
                    'DATA_TYPE'         => $typeArr[0],
                    'UNSIGNED'          => mb_stripos($row['Type'],'unsigned') !== false ? true : false,
                    'PRIMARY'           => $row['Key'] == 'PRI' ? true : false,
                    'NULLABLE'          => $row['Null'] == 'YES' ? true : false,
                    'LENGTH'            => $length_matches[1][0],
                    'IDENTITY'          => mb_stripos($row['Extra'],'auto_increment') !== false,
                    'AUTO_INCREMENT'    => mb_stripos($row['Extra'],'auto_increment') !== false,
                    'GENERATED'         => mb_stripos($row['Extra'],'generated') !== false,
                ];
                $i++;
            }
        }
        if ($memory_cached) {
            $this->cache['db_describeTable_'.$table] = $tableData;
        }
        return $tableData;
    }

    public function listColumns($table_name, $memorycached = true) {
        return array_keys($this->describeTable($table_name, $memorycached));
    }

    public function quote($value) {
        if (is_int($value)) {
            return $value;
        } else if (is_float($value)) {
            return sprintf('%F', $value);
        } else if (is_string($value)) {
            return "'" . $this->mysqli->real_escape_string($value) . "'";
        } else if (is_bool($value)) {
            return $value ? 1 : 0;
        } else if (is_object($value) && method_exists($value, '__toString')) {
            return $this->quote(strval($value));
        } else if (is_array($value) || is_object($value)) {
            return $this->quote(json_encode($value));
        } else if ($value === null || $value === '') {
            return "''";
        } else {
            return $value;
        }
    }

    public function quoteInto($text, $value, $type = null, $count = null) {
        if ($count === null) {
            return str_replace('?', $this->quote($value, $type), $text);
        } else {
            while ($count > 0) {
                if (strpos($text, '?') !== false) {
                    $text = substr_replace($text, $this->quote($value, $type), strpos($text, '?'), 1);
                }
                --$count;
            }
            return $text;
        }
    }

    public function now() {
        return date('Y-m-d H:i:s');
    }

}