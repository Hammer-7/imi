<?php

declare(strict_types=1);

return [
    // 项目根命名空间
    'namespace'    => 'Imi\WorkermanGateway\Test\WorkermanServer',

    // 配置文件
    'configs'    => [
        'beans'        => __DIR__ . '/beans.php',
    ],

    // 扫描目录
    'beanScan'    => [
    ],

    // 组件命名空间
    'components'    => [
        'Workerman'        => 'Imi\Workerman',
        'Swoole'           => 'Imi\Swoole',
        'WorkermanGateway' => 'Imi\WorkermanGateway',
    ],

    // 主服务器配置
    'mainServer'    => [
        'namespace'    => 'Imi\WorkermanGateway\Test\WorkermanServer\WebSocketServer',
        'type'         => \Imi\WorkermanGateway\Swoole\Server\Type::BUSINESS_WEBSOCKET,
        // 'host'         => imiGetEnv('SERVER_HOST', '127.0.0.1'),
        // 'port'         => 13002,
        'mode'         => \SWOOLE_BASE,
        'configs'      => [
            'worker_num'    => 2,
        ],
        'workermanGateway' => [
            'registerAddress'      => '127.0.0.1:13004',
            'worker_coroutine_num' => swoole_cpu_num(),
            'channel'              => [
                'size' => 1024,
            ],
        ],
    ],

    // 子服务器（端口监听）配置
    'subServers'        => [
        'http'     => [
            'namespace' => 'Imi\WorkermanGateway\Test\WorkermanServer\ApiServer',
            'type'      => Imi\Swoole\Server\Type::HTTP,
            'host'      => imiGetEnv('SERVER_HOST', '127.0.0.1'),
            'port'      => 13000,
        ],
    ],

    // Workerman 服务器配置
    'workermanServer' => [
        'http' => [
            'namespace' => 'Imi\WorkermanGateway\Test\WorkermanServer\ApiServer',
            'type'      => Imi\Workerman\Server\Type::HTTP,
            'host'      => imiGetEnv('SERVER_HOST', '127.0.0.1'),
            'port'      => 13000,
            'configs'   => [
                'registerAddress' => '127.0.0.1:13004',
            ],
        ],
        'register' => [
            'namespace'   => 'Imi\WorkermanGateway\Test\WorkermanServer\Register',
            'type'        => Imi\WorkermanGateway\Workerman\Server\Type::REGISTER,
            'host'        => imiGetEnv('SERVER_HOST', '127.0.0.1'),
            'port'        => 13004,
            'configs'     => [
            ],
        ],
        'gateway' => [
            'namespace'   => 'Imi\WorkermanGateway\Test\WorkermanServer\Gateway',
            'type'        => Imi\WorkermanGateway\Workerman\Server\Type::GATEWAY,
            'socketName'  => 'websocket://127.0.0.1:13002',
            'configs'     => [
                'lanIp'           => '127.0.0.1',
                'startPort'       => 12900,
                'registerAddress' => '127.0.0.1:13004',
            ],
        ],
        'websocket' => [
            'namespace'   => 'Imi\WorkermanGateway\Test\WorkermanServer\WebSocketServer',
            'type'        => Imi\WorkermanGateway\Workerman\Server\Type::BUSINESS_WEBSOCKET,
            'configs'     => [
                'registerAddress' => '127.0.0.1:13004',
                'count'           => 2,
            ],
        ],
    ],

    // 数据库配置
    'db'    => [
        // 默认连接池名
        'defaultPool'    => 'maindb',
    ],

    // redis 配置
    'redis' => [
        // 默认连接池名
        'defaultPool'   => 'redis',
        'connections'   => [
            'redis' => [
                'host'        => imiGetEnv('REDIS_SERVER_HOST', '127.0.0.1'),
                'port'        => imiGetEnv('REDIS_SERVER_PORT', 6379),
                'password'    => imiGetEnv('REDIS_SERVER_PASSWORD'),
            ],
        ],
    ],

    // 锁
    'lock'  => [
        'default' => 'redisConnectContextLock',
        'list'    => [
            'redisConnectContextLock' => [
                'class'     => 'RedisLock',
                'options'   => [
                    'poolName'  => 'redis',
                ],
            ],
        ],
    ],

    'workerman' => [
        'imi' => [
            'ServerUtil' => Imi\WorkermanGateway\Workerman\Server\Util\GatewayServerUtil::class,
        ],
    ],

    'swoole' => [
        'imi' => [
            'ServerUtil' => Imi\WorkermanGateway\Swoole\Server\Util\GatewayServerUtil::class,
        ],
    ],
];