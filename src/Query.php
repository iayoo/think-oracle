<?php
/**
 * Create By IaYoo
 * Date 2021/4/7 7:23 下午
 */
namespace iayoo\think;


class Query extends \think\db\Query
{
    public function count(string $field = '*'): int
    {
        if (!empty($this->options['group'])) {
            // 支持GROUP
            $options = $this->getOptions();
            $subSql  = $this->options($options)
                ->field('count( "' . $field . '" ) AS think_count')
                ->bind($this->bind)
                ->buildSql();

            $query = $this->newQuery()->table([$subSql => '_group_count_']);

            $count = $query->aggregate('COUNT', '*');
        } else {
            $count = $this->aggregate('COUNT', $field);
        }

        return (int) $count;
//        return 0;
    }
}