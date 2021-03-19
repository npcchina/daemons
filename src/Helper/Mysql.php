<?php
namespace Npc\Helper;

use Phalcon\Db\Adapter\Pdo\Mysql as PhalconMysql;
use Phalcon\Db\Column;

class Mysql extends PhalconMysql
{
    public function showDatabases()
    {
        return $this->fetchAll('show databases');
    }

    /**
     * 获取表
     * @param string $TABLE_SCHEMA
     * @return array
     */
    public function showTables($TABLE_SCHEMA = '')
    {
        return $this->fetchAll('select TABLE_NAME,TABLE_COMMENT from information_schema.TABLES where TABLE_SCHEMA = ' . $this->escapeString($TABLE_SCHEMA));

        $tables = $this->fetchAll('SELECT TABLE_NAME,TABLE_COMMENT from information_schema.TABLES where TABLE_SCHEMA = ' . $this->escapeString($TABLE_SCHEMA));
        if (!$tables) {
            $rows = $this->fetchAll('show tables from ' . $this->escapeIdentifier($database));

            foreach ($rows as $row) {
                $tables[] = [
                    'TABLE_NAME' => $row['Tables_in_' . $database],
                ];
            }
        }
    }

    /**
     * 获取DDL
     * @param string $TABLE_SCHEMA
     * @return mixed
     */
    public function showCreate($TABLE_SCHEMA = '')
    {
        return $this->query('show create table ' . $this->escapeIdentifier($TABLE_SCHEMA))->fetch()['Create Table'];
    }

    /**
     * 获取索引
     * @param string $TABLE_SCHEMA
     * @return array
     */
    public function showIndex($TABLE_SCHEMA = '')
    {
        return $this->fetchAll('show index from ' . $this->escapeIdentifier($TABLE_SCHEMA) . '');
    }

    /**
     * 获取字段
     * @param string $TABLE_SCHEMA
     * @return array
     */
    public function showFullFields($TABLE_SCHEMA = '')
    {
        $fields = $this->fetchAll('show full fields from ' . $this->escapeIdentifier($TABLE_SCHEMA) . '');

        foreach ($fields as $key => $val) {
            list($type, $value) = explode('(', $val['Type']);
            $fields[$key]['ID'] = $val['Field'];
            $fields[$key]['Type'] = $type;
            $fields[$key]['Value'] = $value ? str_replace(array('(', ')'), '', $value) : '';
            //这里有个BUG 没有PRI 的时候 mysql 会把 UNI 显示为 PRI
            $fields[$key]['Index'] = $val['Key'] == 'PRI' ? '主键' : ($val['Key'] == 'UNI' ? '唯一' : ($val['Key'] == 'MUL' ? '索引' : ''));
            $fields[$key]['A_I'] = stripos($val['Extra'], 'auto_increment') !== false ? '是' : '否';
            $fields[$key]['Null'] = $val['Null'] == 'YES' ? '是' : '否';
            $fields[$key]['Collation'] = $val['Collation'] == 'utf8_general_ci' ? 'utf8' : '';
        }

        return $fields;
    }

    /**
     * 生成 alter table 语句 -- 从 pma 代码拷贝创意
     *
     * @param $oldcol
     * @param $newcol
     * @param $type
     * @param $length
     * @param $attribute
     * @param $collation
     * @param $null
     * @param $default_type
     * @param $default_value
     * @param $extra
     * @param string $comment
     * @param $field_primary
     * @param $index
     * @param $default_orig
     * @return string
     */
    public function generateAlter($oldcol, $newcol, $type, $length,
                                   $attribute, $collation, $null, $default_type, $default_value,
                                   $extra, $comment = '', &$field_primary, $index, $default_orig)
    {
        return $this->escapeIdentifier($oldcol) . ' '
            . $this->generateFieldSpec(
                $newcol, $type, $length, $attribute,
                $collation, $null, $default_type, $default_value, $extra,
                $comment, $field_primary, $index, $default_orig
            );
    }

    /**
     * 生成 charset 逻辑 -- 从 pma 代码拷贝创意
     *
     * @param $collation
     * @return string
     */
    public static function PMA_generateCharsetQueryPart($collation)
    {
        if (!0) {
            list($charset) = explode('_', $collation);
            return ' CHARACTER SET ' . $charset . ($charset == $collation ? '' : ' COLLATE ' . $collation);
        } else {
            return ' COLLATE ' . $collation;
        }
    }

    /**
     * 生成 字段定义 语句 -- 从 pma 代码拷贝创意
     *
     * @param $name
     * @param $type
     * @param string $length
     * @param string $attribute
     * @param string $collation
     * @param bool|false $null
     * @param string $default_type
     * @param string $default_value
     * @param string $extra
     * @param string $comment
     * @param $field_primary
     * @param $index
     * @return string
     */
    public function generateFieldSpec($name, $type, $length = '', $attribute = '',
                                       $collation = '', $null = false, $default_type = 'USER_DEFINED',
                                       $default_value = '', $extra = '', $comment = '',
                                       &$field_primary, $index,$default_orig)
    {
        $is_timestamp = strpos(strtoupper($type), 'TIMESTAMP') !== false;

        //加入 '' 强制转换下 不然数字报错
        $query = $this->escapeIdentifier('' . $name) . ' ' . $type;

        if ($length != ''
            && !preg_match('@^(DATE|DATETIME|TIME|TINYBLOB|TINYTEXT|BLOB|TEXT|'
                . 'MEDIUMBLOB|MEDIUMTEXT|LONGBLOB|LONGTEXT|SERIAL|BOOLEAN|UUID)$@i', $type)
        ) {

            //支持  int（10） unsigned
            list($length, $def) = explode(' ', $length);

            $query .= '(' . $length . ') ' . $def;
        }

        if ($attribute != '') {
            $query .= ' ' . $attribute;
        }

        if (!empty($collation) && $collation != 'NULL'
            && preg_match('@^(TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT|VARCHAR|CHAR|ENUM|SET)$@i', $type)
        ) {
            $query .= $this->PMA_generateCharsetQueryPart($collation);
        }

        if ($null !== false) {
            if ($null == 'NULL') {
                $query .= ' NULL';
            } else {
                $query .= ' NOT NULL';
            }
        }

        switch ($default_type) {
            case 'USER_DEFINED' :
                if ($is_timestamp && $default_value === '0') {
                    // a TIMESTAMP does not accept DEFAULT '0'
                    // but DEFAULT 0 works
                    $query .= ' DEFAULT 0';
                } elseif ($type == 'BIT') {
                    $query .= ' DEFAULT b\''
                        . preg_replace('/[^01]/', '0', $default_value)
                        . '\'';
                } elseif ($type == 'BOOLEAN') {
                    if (preg_match('/^1|T|TRUE|YES$/i', $default_value)) {
                        $query .= ' DEFAULT TRUE';
                    } elseif (preg_match('/^0|F|FALSE|NO$/i', $default_value)) {
                        $query .= ' DEFAULT FALSE';
                    } else {
                        // Invalid BOOLEAN value
                        $query .= ' DEFAULT ' . $this->escapeString($default_value) . '';
                    }
                } else {
                    $query .= ' DEFAULT ' . $this->escapeString($default_value) . '';
                }
                break;
            case 'NULL' :
            case 'CURRENT_TIMESTAMP' :
                $query .= ' DEFAULT ' . $default_type;
                break;
            case 'NONE' :
            default :
                break;
        }

        if (!empty($extra)) {
            $query .= ' ' . $extra;
        }
        if (!empty($comment)) {
            $query .= " COMMENT " . $this->escapeString($comment) . "";
        }
        return $query;
    }

    /**
     * 资源表修改逻辑
     *
     * 表存在 支持字段修正、字段新增、索引新增（不支持新增组合索引）
     * 表不存在 支持字段创建、索引新增、创建组合主键（不支持组合索引）
     *
     * @param string $table
     * @param array $posts
     * @param string $table_comment
     * @return bool
     */
    public function modifyTable($table = '' , $posts = [] , $table_comment = '')
    {
        $definitions = [];
        $field_primary = [];
        $field_index = [];
        $field_unique = [];
        $field_fulltext = [];

        try{
            //尝试判断表是否存在
            $this->query('show create table ' . $this->escapeIdentifier($table));

            //表存在 修改逻辑
            foreach ($posts as $field => $values) {
                parse_str($values, $fields);

                if ($fields['Index'] == '主键' || $fields['A_I'] == '是') {
                    $field_primary[$field] = $this->escapeIdentifier($field);
                }
                if ($fields['Index'] == '唯一') {
                    $field_unique[$field] = $this->escapeIdentifier($field);
                }
                if ($fields['Index'] == '索引') {
                    $field_index[$field] = $this->escapeIdentifier($field);
                }
                if ($fields['Index'] == '全文检索') {
                    $field_fulltext[$field] = $this->escapeIdentifier($field);
                }

                if ($fields['ID']) {
                    $definitions[] = ' CHANGE '.$this->generateAlter(
                        $fields['ID'],
                        $field,
                        $fields['Type'] ? $fields['Type'] : 'INT',
                        $fields['Value'],
                        '', //属性字段
                        $fields['Collation'],
                        $fields['Null'] == '是'
                            ? 'NULL'
                            : 'NOT NULL',
                        $fields['Default'] == '' ? 'NONE' : 'USER_DEFINED',
                        $fields['Default'] == '' ? false : $fields['Default'],
                        $fields['A_I'] == '是'
                            ? 'AUTO_INCREMENT'
                            : false,
                        $fields['Comment'],
                        $ref,
                        $field,
                        ''
                    );
                } else {
                    $definitions[] = ' ADD '.$this->generateFieldSpec(
                        $field,
                        $fields['Type'] ? $fields['Type'] : 'INT',
                        $fields['Value'],
                        '',
                        $fields['Collation'],
                        $fields['Null'] == '是'
                            ? 'NULL'
                            : 'NOT NULL',
                        $fields['Default'] == '' ? 'NONE' : 'USER_DEFINED',
                        $fields['Default'] == '' ? false : $fields['Default'],
                        $fields['A_I'] == '是'
                            ? 'AUTO_INCREMENT'
                            : false,
                        $fields['Comment'],
                        $ref,
                        $field,
                        ''
                    );
                }
            }

            //尝试获取索引
            try {
                $index = $this->showIndex($table);
                foreach ($index as $k => $v) {
                    //防止主键冲突
                    if ($v['Key_name'] == 'PRIMARY') unset($field_primary[$v['Column_name']]);
                    //防止反复添加唯一索引
                    if ($v['Non_unique'] == 0) unset($field_unique[$v['Column_name']]);
                    //防止已有索引的更新请求
                    unset($field_index[$v['Column_name']]);
                }
            } catch  (\Exception $e) {

            }

            if (count($field_primary)) {
                $definitions[] = ' ADD PRIMARY KEY (' . implode(', ', $field_primary) . ') ';
            }

            if (count($field_index)) {
                foreach($field_index as $index)
                {
                    $definitions[] = ' ADD INDEX (' . $index . ') ';
                }
            }

            if (count($field_unique)) {
                foreach($field_unique as $index) {
                    $definitions[] = ' ADD UNIQUE (' . $index . ') ';
                }
            }

            if (count($field_fulltext)) {
                foreach($field_fulltext as $index) {
                    $definitions[] = ' ADD FULLTEXT (' . $index . ') ';
                }
            }

            $sql = '';
            try {
                //echo 'ALTER TABLE ' . $this->escapeIdentifier($table) . implode(' , ', $definitions);
                $definitions && $this->query('ALTER TABLE ' . $this->escapeIdentifier($table) . implode(' , ', $definitions));
            } catch  (\Exception $e) {
                throw new \Exception($e->getMessage()."\n".'ALTER TABLE ' . $this->escapeIdentifier($table) . implode(' , ', $definitions));
            }
        }
        catch (\Exception $e)
        {
            //以下是尝试用Phalcon 实现 TODO
//            $type = [
//                'INT' => Column::TYPE_INTEGER,
//                'TINYINT' => Column::TYPE_TINYINTEGER,
//                'CHAR' => Column::TYPE_CHAR,
//                'VARCHAR' => Column::TYPE_VARCHAR,
//                'TEXT' => Column::TYPE_TEXT,
//                'MEDIUMTEXT' => Column::TYPE_MEDIUMTEXT,
//                'ENUM' => Column::TYPE_ENUM,
//                'DATE' => Column::TYPE_DATE,
//                'DATETIME' => Column::TYPE_DATETIME,
//                'TIMESTAMP' => Column::TYPE_TIMESTAMP,
//                'TIME' => Column::TYPE_TIME,
//                'SMALLINT' => Column::TYPE_SMALLINTEGER,
//                'MEDIUMINT' => Column::TYPE_MEDIUMINTEGER,
//                'BIGINT' => Column::TYPE_BIGINTEGER,
//                'DECIMAL' => Column::TYPE_DECIMAL,
//                'FLOAT' => Column::TYPE_FLOAT,
//                'DOUBLE' => Column::TYPE_DOUBLE,
//                'TINYTEXT' => Column::TYPE_TINYINTEGER,
//                'LONGTEXT' => Column::TYPE_LONGTEXT,
//                'BINARY' => Column::TYPE_BIT,
//                'TINYBLOB' => Column::TYPE_TINYBLOB,
//                'MEDIUMBLOB' => Column::TYPE_MEDIUMBLOB,
//                'BLOB' => Column::TYPE_BLOB,
//                'LONGBLOB' => Column::TYPE_LONGBLOB,
//            ];
//            foreach ($posts as $field => $values) {
//                parse_str($values, $fields);
//
//                $definitions['columns'][] = new Column(
//                    $field,
//                    [
//                        'type' => isset($type[$fields['type']]) ? $type[$fields['type']] : Column::TYPE_INTEGER,
//                        'primary' => $fields['A_I'] == '是' ? true : ($fields['Index'] == '主键' ?  true : false),
//                        'size' => $fields['Value'] ? $fields['Value'] : 10,
//                        'scale' => '',
//                        'unsigned' => '',
//                        'notNull' => $fields['Null'] == '是' ? false : true,
//                        'default' =>  $fields['Default'] ? $fields['Default'] : null,
//                        'autoIncrement' => $fields['A_I'] == '是' ? true : false,
//                    ]
//                );
//            }
//            return $this->createTable($table,'',$definitions);

            foreach ($posts as $field => $values) {
                parse_str($values, $fields);

                if ($fields['Index'] == '主键' || $fields['A_I'] == '是') {
                    $field_primary[$field] = $this->escapeIdentifier($field);
                }
                if ($fields['Index'] == '唯一') {
                    $field_unique[$field] = $this->escapeIdentifier($field);
                }
                if ($fields['Index'] == '索引') {
                    $field_index[$field] = $this->escapeIdentifier($field);
                }
                if ($fields['Index'] == '全文检索') {
                    $field_fulltext[$field] = $this->escapeIdentifier($field);
                }

                $definitions[] = $this->generateFieldSpec(
                    $field,
                    $fields['Type'] ? $fields['Type'] : 'INT',
                    $fields['Value'],
                    '',
                    $fields['Collation'],
                    $fields['Null'] == '是'
                        ? 'NULL'
                        : 'NOT NULL',
                    $fields['Default'] == '' ? 'NONE' : 'USER_DEFINED',
                    $fields['Default'] == '' ? false : $fields['Default'],
                    $fields['A_I'] == '是'
                        ? 'AUTO_INCREMENT'
                        : false,
                    $fields['Comment'],
                    $ref,
                    $field,
                    ''
                );
            }
            if (count($field_primary)) {
                $definitions[] = ' PRIMARY KEY (' . implode(', ', $field_primary) . ') ';
            }

            if (count($field_index)) {
                foreach($field_index as $index)
                {
                    $definitions[] = ' INDEX (' . $index . ') ';
                }
            }

            if (count($field_unique)) {
                foreach($field_unique as $index) {
                    $definitions[] = ' UNIQUE (' . $index . ') ';
                }
            }

            if (count($field_fulltext)) {
                foreach($field_fulltext as $index) {
                    $definitions[] = ' FULLTEXT (' . $index . ') ';
                }
            }

            $this->query('CREATE TABLE ' . $this->escapeIdentifier($table) . ' (' . implode(',',$definitions) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT=\''.$table_comment.'\'');
        }

        return true;
    }

    /**
     * 删除字段
     *
     * @param string $tableName
     * @param string $schemaName
     * @param string $columnName
     * @return bool
     */
    public function dropColumn(string $tableName, string $schemaName, string $columnName): bool
    {
        return parent::dropColumn($tableName,$schemaName,$columnName);
        $this->query('ALTER TABLE ' .$this->escapeIdentifier($tableName).' DROP '.$this->escapeIdentifier($col_name));
        return true;
    }

    /**
     * 删除索引
     * @param string $tableName
     * @param string $schemaName
     * @param mixed $indexName
     * @return bool
     */
    public function dropIndex(string $tableName, string $schemaName, $indexName): bool
    {
        //return parent::dropIndex($tableName,$schemaName,$indexName);
        $this->query('ALTER TABLE ' .$this->escapeIdentifier($tableName).' DROP INDEX '.$this->escapeIdentifier($indexName));
        return true;
    }
}