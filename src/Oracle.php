<?php
/**
 * Create By IaYoo
 * Date 2021/4/7 7:23 下午
 */
namespace iayoo\think;


use think\db\BaseQuery;
use think\db\Connection;
use think\db\exception\BindParamException;
use think\db\exception\DbException;

class Oracle extends \think\db\connector\Oracle
{
    /**
     * 取得数据表的字段信息
     * @access public
     * @param string $tableName
     * @return array
     * @throws DbException
     */
    public function getFields(string $tableName): array
    {
        [$tableName] = explode(' ', $tableName);
        $sql         = "select a.column_name,data_type,DECODE (nullable, 'Y', 0, 1) notnull,data_default, DECODE (A .column_name,b.column_name,1,0) pk from all_tab_columns a,(select column_name from all_constraints c, all_cons_columns col where c.constraint_name = col.constraint_name and c.constraint_type = 'P' and c.table_name = '" . strtolower($tableName) . "' ) b where table_name = '" . strtolower($tableName) . "' and a.column_name = b.column_name (+)";

        $pdo    = $this->getPDOStatement($sql);
        $result = $pdo->fetchAll(\PDO::FETCH_ASSOC);
        $info   = [];

        if ($result) {
            foreach ($result as $key => $val) {
                $val = array_change_key_case($val);

                $info[$val['column_name']] = [
                    'name'    => $val['column_name'],
                    'type'    => $val['data_type'],
                    'notnull' => $val['notnull'],
                    'default' => $val['data_default'],
                    'primary' => $val['pk'],
                    'autoinc' => $val['pk'] && in_array($val['data_type'], ['integer', 'number', 'int', 'smallint']) ? 1 : 0, // 根据字段类型来确定，字符串主键哪来都自增
                ];
            }
        }

        return $this->fieldCase($info);
    }

    /**
     * 获取最近插入的ID
     * @access public
     * @param BaseQuery $query 查询对象
     * @param string|null $sequence 自增序列名
     * @return mixed
     */
    public function getLastInsID(BaseQuery $query, string $sequence = null)
    {
        if (!$sequence){
            $sequence = $this->getPk($query->getTable());
        }
        if ($sequence){
            try{
                return $this->linkID->lastInsertId($sequence);
            }catch (\PDOException $e){
                $option = $query->getOptions();
                $tableName = str_replace(env('database.prefix'),'',$option['table']);
                $seqPre = env('oracle.seq_pre','seq');
                $sql = "select \"{$seqPre}_{$tableName}\".currval as id from dual";
                $pdo    = $this->linkID->query($sql);
                return $pdo->fetchColumn();
            }
        }
        return -1;
//        return $sequence ? $this->linkID->lastInsertId($sequence) : -1;
    }

    /**
     * 获取数据表的主键
     * @access public
     * @param mixed $tableName 数据表名
     * @return string|array
     */
    public function getPk($tableName)
    {
        return $this->getTableInfo($tableName, 'pk');
    }

    /**
     * 根据表名，表前缀，序列前缀，获取oracle数据库序列名称
     *
     * @param BaseQuery $query
     * @param array|null $options
     * @return void
     * @throws DbException
     */
    protected function getSequence(BaseQuery $query, array $options = null)
    {
        $sequence  = $options['sequence'] ?? null;

        if(!$sequence) {
            if(!empty($options) && isset($this->config['prefix_sequence']) && $this->config['prefix_sequence'] && !empty($options['table'])) {
                $seqtemp = $this->config['prefix_sequence'] . str_ireplace($this->config['prefix'], "", $options['table']);
                $sql     = "SELECT COUNT(*) SEQ_COUNT FROM user_sequences WHERE sequence_name='" . strtoupper($seqtemp) . "'";
                $stmt    = $this->queryPDOStatement($query, $sql, []);
                if ($stmt->fetchColumn()) {
                    $sequence = $seqtemp;
                }
            }
        }

        return $sequence;
    }

    /**
     * 插入记录
     * @access public
     * @param BaseQuery $query 查询对象
     * @param boolean $getLastInsID 返回自增主键
     * @return mixed
     * @throws DbException
     */
    public function insert(BaseQuery $query, bool $getLastInsID = false)
    {
        // 分析查询表达式
        $options = $query->parseOptions();

        // 生成SQL语句
        $sql = $this->builder->insert($query);
        $query->sequence('id');

        // 执行操作
        $result = '' == $sql ? 0 : $this->pdoExecute($query, $sql, $query->getBind());

        if ($result) {
            $sequence  = $this->getSequence($query, $options);  // 改变获取 sequence 的方式
            $lastInsId = $this->getLastInsID($query, $sequence);

            $data = $options['data'];

            if ($lastInsId) {
                $pk = $query->getAutoInc();
                if ($pk) {
                    $data[$pk] = $lastInsId;
                }
            }

            $query->setOption('data', $data);

            $this->db->trigger('after_insert', $query);

            if ($getLastInsID && $lastInsId) {
                return $lastInsId;
            }
        }

        return $result;
    }

    public function insertAll(BaseQuery $query, array $dataSet = [], int $limit = 0): int
    {
        if (!is_array(reset($dataSet))) {
            return 0;
        }
        $options = $query->parseOptions();
        $replace = !empty($options['replace']);

        if (0 === $limit && count($dataSet) >= 5000) {
            $limit = 1000;
        }

        if ($limit) {
            // 分批写入 自动启动事务支持
            $this->startTrans();

            try {
                $array = array_chunk($dataSet, $limit, true);
                $count = 0;

                foreach ($array as $item) {
                    $sql = $this->builder->insertAll($query, $item, $replace);
                    $count += $this->pdoExecute($query, $sql, $query->getBind());
                }

                // 提交事务
                $this->commit();
            } catch (\Exception | \Throwable $e) {
                $this->rollback();
                throw $e;
            }

            return $count;
        }

        $sql = $this->builder->insertAll($query, $dataSet, $replace);

        return $this->pdoExecute($query, $sql, $query->getBind());
    }

    /**
     * 存储过程的输入输出参数绑定
     * @access public
     * @param array $bind 要绑定的参数列表
     * @return void
     * @throws BindParamException
     */
    protected function bindParam(array $bind): void
    {
        foreach ($bind as $key => $val) {
            $param = is_numeric($key) ? $key + 1 : ':' . $key;

            if (is_array($val)) {
                $result = $this->PDOStatement->bindParam($param, $bind[$key][0], $bind[$key][1], $bind[$key][2] ?? -1);
                // array_unshift($val, $param);
                // $result = call_user_func_array([$this->PDOStatement, 'bindParam'], $val); // 不支持不能引用的参数
            } else {
                $result = $this->PDOStatement->bindValue($param, $val);
            }

            if (!$result) {
                $param = array_shift($val);

                throw new BindParamException(
                    "Error occurred  when binding parameters '{$param}'",
                    $this->config,
                    $this->getLastsql(),
                    $bind
                );
            }
        }
    }

}