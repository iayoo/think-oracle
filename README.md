# think-oracle

基于官方库 `think-oracle` 上做了一些兼容修改。
使用了 `oracle-19c` 的数据库版本进行测试，其它版本尚未测试。

## 依赖

- thinkphp 6.0
- think-orm 2.0 及以上
- php 7.1 及以上


## 安装

```
composer require iayoo/think-oracle
```

打开 `config/database.php` 配置文件 , 在 `connections` 参数下新增以下内容

```php
'oracle' => [
    // 数据库类型
    'type'            => env('oracle.type', '\iayoo\think\Oracle'),
    // 服务器地址
    'hostname'        => env('oracle.hostname', '127.0.0.1'),
    // 数据库名
    'database'        => env('oracle.database', ''),
    // 用户名
    'username'        => env('oracle.username', ''),
    // 密码
    'password'        => env('oracle.password', ''),
    // 端口
    'hostport'        => env('oracle.hostport', '1521'),
    // 数据库连接参数
    'params'          => [],
    // 数据库编码默认采用utf8
    'charset'         => env('database.charset', 'utf8'),
    // 数据库表前缀
    'prefix'          => env('database.prefix', ''),

    // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
    'deploy'          => 0,
    // 数据库读写是否分离 主从式有效
    'rw_separate'     => false,
    // 读写分离后 主服务器数量
    'master_num'      => 1,
    // 指定从服务器序号
    'slave_no'        => '',
    // 是否严格检查字段是否存在
    'fields_strict'   => true,
    // 是否需要断线重连
    'break_reconnect' => false,
    // 监听SQL
    'trigger_sql'     => env('app_debug', true),
    // 开启字段缓存
    'fields_cache'    => false,
    // Builder类
    'builder'         => 'iayoo\think\Builder',
    // Query类
    'query'           => 'iayoo\think\Query',
]
```

然后进入环境配置文件中 `.env` 下，新增以下内容

```
[DATABASE]
DRIVER = oracle
PREFIX = 你的数据库表前缀

[ORACLE]
HOSTNAME = 数据库地址
DATABASE = 数据库SID
USERNAME = 数据库用户名
PASSWORD = 数据库密码
PREFIX = 表前缀
HOSTPORT = 端口号
CHARSET = utf8
DEBUG = false
SEQ_PRE= 自增序列前缀
```