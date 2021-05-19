<?php
/**
 * Create By IaYoo
 * Date 2021/4/7 7:23 下午
 */
namespace iayoo\think;


use think\db\exception\DbException as Exception;
use think\db\Query;
use think\db\Raw;

class Builder extends \think\db\builder\Oracle
{
    protected $selectSql = 'SELECT * FROM (SELECT "thinkphp".*, rownum AS numrow FROM (SELECT %FIELD% FROM %TABLE%%JOIN%%WHERE%%GROUP%%HAVING%%ORDER%) "thinkphp" ) %LIMIT%%COMMENT%';

    /**
     * INSERT SQL表达式
     * @var string
     */
    protected $insertSql ='%INSERT%%EXTRA% INTO %TABLE%%PARTITION% (%FIELD%) VALUES ( %DATA% ) %DUPLICATE%%COMMENT%';

    /**
     * INSERT ALL SQL表达式
     * @var string
     */
    protected $insertAllSql = '%INSERT% %DATA% %COMMENT% SELECT * FROM dual';

    /**
     * 字段和表名处理
     * @access public
     * @param Query $query 查询对象
     * @param mixed $key 字段名
     * @param bool $strict 严格检测
     * @return string
     * @throws Exception
     */
    public function parseKey(Query $query, $key, bool $strict = false): string
    {
        if (is_int($key)) {
            return (string) $key;
        } elseif ($key instanceof Raw) {
            return $this->parseRaw($query, $key);
        }
        $key = trim($key);

        if (strpos($key, '->>') && false === strpos($key, '(')) {
            // JSON字段支持
            [$field, $name] = explode('->>', $key, 2);

            return $this->parseKey($query, $field, true) . '->>\'$' . (strpos($name, '[') === 0 ? '' : '.') . str_replace('->>', '.', $name) . '\'';
        } elseif (strpos($key, '->') && false === strpos($key, '(')) {
            // JSON字段支持
            [$field, $name] = explode('->', $key, 2);
            return 'json_extract(' . $this->parseKey($query, $field, true) . ', \'$' . (strpos($name, '[') === 0 ? '' : '.') . str_replace('->', '.', $name) . '\')';
        } elseif (strpos($key, '.') && !preg_match('/[,\'\"\(\)`\s]/', $key)) {
            [$table, $key] = explode('.', $key, 2);

            $alias = $query->getOptions('alias');

            if ('__TABLE__' == $table) {
                $table = $query->getOptions('table');
                $table = is_array($table) ? array_shift($table) : $table;
            }

            if (isset($alias[$table])) {
                $table = $alias[$table];
            }
        } elseif (preg_match('/\s[Aa][Ss]\s/',$key) && !preg_match('/[,\'\"\(\)`]/', $key)){
            // 处理 AS/As/as/aS 的情况
            $p = '/.* ([Aa][Ss]) .*/';
            preg_match_all($p,$key,$matches);
            if (isset($matches[1][0])){
                $res = explode($matches[1][0],$key);
                // 去除空格
                if (isset($res[1])){
                    $key = $this->parseKey($query,$res[0]) . " AS " . '"' . trim($res[1]) . '"';
                }
            }
        }elseif (preg_match('/\s/',$key) && !preg_match('/[,\'\"\(\)`]/', $key)){
            $key = trim($key);
            if (strpos($key, ' ')) {
                // 别名使用空格的情况
                [$tableKey,$key] = explode(' ',$key);
                $key = $this->parseKey($query, $tableKey) . " AS " . '"' . trim($key) . '"';
            }
        }
        if ($strict && !preg_match('/^[\w\.\*]+$/', $key)) {
            throw new Exception('not support data:' . $key);
        }

        if ('*' != $key && !preg_match('/[,\'\"\*\(\)`.\s]/', $key)) {
            $key = '"' . $key . '"';
        }

        if (isset($table)) {
            if (strpos($table, '.')) {
                $table = str_replace('.', '"."', $table);
            }
            $key = '"' . $table . '".' . $key;
        }
        return $key;
    }

    /**
     * field分析
     * @access protected
     * @param Query $query 查询对象
     * @param mixed $fields 字段名
     * @return string
     * @throws Exception
     */
    protected function parseField(Query $query, $fields): string
    {
        if (is_array($fields)) {
            // 支持 'field1'=>'field2' 这样的字段别名定义
            $array = [];

            foreach ($fields as $key => $field) {
                if ($field instanceof Raw) {
                    $array[] = $this->parseRaw($query, $field);
                } elseif (!is_numeric($key)) {
                    $array[] = $this->parseKey($query, $key) . ' AS ' . $this->parseKey($query, $field, true);
                } else {
                    $array[] = $this->parseKey($query, $field);
                }
            }

            $fieldsStr = implode(',', $array);
        } else {
            $fieldsStr = '*';
        }

        return $fieldsStr;
    }

    /**
     * 生成Insert SQL
     * @access public
     * @param Query $query 查询对象
     * @return string
     * @throws Exception
     */
    public function insert(Query $query): string
    {
        $options = $query->getOptions();
        // 分析并处理数据
        $data = $this->parseData($query, $options['data']);

        if (empty($data)) {
            return '';
        }

        $set = [];
        foreach ($data as $key => $val) {
            $set[] = $key . ' = ' . $val;
        }
        return str_replace(
            ['%INSERT%', '%EXTRA%', '%TABLE%', '%PARTITION%', '%FIELD%', '%DATA%', '%DUPLICATE%', '%COMMENT%'],
            [
                'INSERT',
                $this->parseExtra($query, $options['extra']),
                $this->parseTable($query, $options['table']),
                $this->parsePartition($query, $options['partition']),
                implode(' , ', array_keys($data)),
                implode(' , ', array_values($data)),
                $this->parseDuplicate($query, $options['duplicate']),
                $this->parseComment($query, $options['comment']),
            ],
            $this->insertSql);
    }

    public function insertAll(Query $query, array $dataSet): string
    {
        $options = $query->getOptions();

        // 获取绑定信息
        $bind = $query->getFieldsBindType();

        // 获取合法的字段
        if ('*' == $options['field']) {
            $allowFields = array_keys($bind);
        } else {
            $allowFields = $options['field'];
        }

        $fields = [];
        $values = [];

        foreach ($dataSet as $k => $data) {
            $data = $this->parseData($query, $data, $allowFields, $bind);
            if (!isset($insertFields)) {
                $insertFields = array_keys($data);
            }
            foreach ($insertFields as $field) {
                if (!in_array($this->parseKey($query, $field),$fields)){
                    $fields[] =$this->parseKey($query, $field);
                }
            }
            $values[] = 'INTO ' . $this->parseTable($query, $options['table']) . ' ( ' . implode(',',$fields) . ' ) ' .  ' VALUES (' . implode(',', array_values($data)) . ")";
        }

        return str_replace(
            ['%INSERT%', '%TABLE%', '%EXTRA%', '%DATA%', '%COMMENT%'],
            [
                !empty($options['replace']) ? 'REPLACE' : 'INSERT ALL',
                $this->parseTable($query, $options['table']),
                $this->parseExtra($query, $options['extra']),
                implode(' ', $values),
                $this->parseComment($query, $options['comment']),
            ],
            $this->insertAllSql);
    }

    /**
     * Partition 分析
     * @access protected
     * @param  Query        $query    查询对象
     * @param  string|array $partition  分区
     * @return string
     */
    protected function parsePartition(Query $query, $partition): string
    {
        if ('' == $partition) {
            return '';
        }

        if (is_string($partition)) {
            $partition = explode(',', $partition);
        }

        return ' PARTITION (' . implode(' , ', $partition) . ') ';
    }

    /**
     * ON DUPLICATE KEY UPDATE 分析
     * @access protected
     * @param Query $query 查询对象
     * @param mixed $duplicate
     * @return string
     * @throws Exception
     */
    protected function parseDuplicate(Query $query, $duplicate): string
    {
        if ('' == $duplicate) {
            return '';
        }

        if ($duplicate instanceof Raw) {
            return ' ON DUPLICATE KEY UPDATE ' . $this->parseRaw($query, $duplicate) . ' ';
        }

        if (is_string($duplicate)) {
            $duplicate = explode(',', $duplicate);
        }

        $updates = [];
        foreach ($duplicate as $key => $val) {
            if (is_numeric($key)) {
                $val       = $this->parseKey($query, $val);
                $updates[] = $val . ' = VALUES(' . $val . ')';
            } elseif ($val instanceof Raw) {
                $updates[] = $this->parseKey($query, $key) . " = " . $this->parseRaw($query, $val);
            } else {
                $name      = $query->bindValue($val, $query->getConnection()->getFieldBindType($key));
                $updates[] = $this->parseKey($query, $key) . " = :" . $name;
            }
        }

        return ' ON DUPLICATE KEY UPDATE ' . implode(' , ', $updates) . ' ';
    }

    /**
     * 数据分析
     * @access protected
     * @param Query $query 查询对象
     * @param array $data 数据
     * @param array $fields 字段信息
     * @param array $bind 参数绑定
     * @return array
     * @throws Exception
     */
    protected function parseData(Query $query, array $data = [], array $fields = [], array $bind = []): array
    {
        if (empty($data)) {
            return [];
        }

        $options = $query->getOptions();

        // 获取绑定信息
        if (empty($bind)) {
            $bind = $query->getFieldsBindType();
        }

        if (empty($fields)) {
            if ('*' == $options['field']) {
                $fields = array_keys($bind);
            } else {
                $fields = $options['field'];
            }
        }

        $result = [];

        foreach ($data as $key => $val) {
            $item = $this->parseKey($query, $key, true);

            if ($val instanceof Raw) {
                $result[$item] = $this->parseRaw($query, $val);
                continue;
            } elseif (!is_scalar($val) && (in_array($key, (array) $query->getOptions('json')) || 'json' == $query->getFieldType($key))) {
                $val = json_encode($val);
            }

            if (false !== strpos($key, '->')) {
                [$key, $name]  = explode('->', $key, 2);
                $item          = $this->parseKey($query, $key);
                $result[$item] = 'json_set(' . $item . ', \'$.' . $name . '\', ' . $this->parseDataBind($query, $key . '->' . $name, $val, $bind) . ')';
            } elseif (false === strpos($key, '.') && !in_array($key, $fields, true)) {
                if ($options['strict']) {
                    throw new Exception('fields not exists:[' . $key . ']');
                }
            } elseif (is_null($val)) {
                $result[$item] = 'NULL';
            } elseif (is_array($val) && !empty($val) && is_string($val[0])) {
                switch (strtoupper($val[0])) {
                    case 'INC':
                        $result[$item] = $item . ' + ' . floatval($val[1]);
                        break;
                    case 'DEC':
                        $result[$item] = $item . ' - ' . floatval($val[1]);
                        break;
                }
            } elseif (isset($options['field_type'][$key]) && strtolower($options['field_type'][$key]) == 'date'){
                // 处理date类型的数据
                $result[$item] = "to_date(" .  $this->parseDataBind($query, $key, $val, $bind) .",'yyyy-mm-dd HH24:MI:SS')";
            }elseif (is_scalar($val)) {
                // 过滤非标量数据
                $result[$item] = $this->parseDataBind($query, $key, $val, $bind);
            }
        }

        return $result;
    }
}