<?php

declare(strict_types=1);

namespace Imi\Swoole\Server\DataParser;

use Imi\ConnectContext;
use Imi\RequestContext;
use Imi\Server\ServerManager;
use Imi\Swoole\Server\Contract\ISwooleServer;

/**
 * 数据处理器.
 */
class DataParser
{
    /**
     * 编码为存储格式.
     *
     * @param mixed       $data
     * @param string|null $serverName
     *
     * @return mixed
     */
    public function encode($data, ?string $serverName = null)
    {
        if ($serverName)
        {
            return ServerManager::getServer($serverName, ISwooleServer::class)->getBean($this->getParserClass($serverName))->encode($data);
        }
        else
        {
            return RequestContext::getServerBean($this->getParserClass($serverName))->encode($data);
        }
    }

    /**
     * 解码为php变量.
     *
     * @param mixed       $data
     * @param string|null $serverName
     *
     * @return mixed
     */
    public function decode($data, ?string $serverName = null)
    {
        if ($serverName)
        {
            return ServerManager::getServer($serverName, ISwooleServer::class)->getBean($this->getParserClass($serverName))->decode($data);
        }
        else
        {
            return RequestContext::getServerBean($this->getParserClass($serverName))->decode($data);
        }
    }

    /**
     * 获取处理器类.
     *
     * @param string|null $serverName
     *
     * @return string
     */
    public function getParserClass(?string $serverName = null): string
    {
        $requestContext = RequestContext::getContext();
        if ($serverName)
        {
            $server = ServerManager::getServer($serverName, ISwooleServer::class);
        }
        else
        {
            $server = $requestContext['server'] ?? null;
        }
        if ($server instanceof \Imi\Swoole\Server\WebSocket\Server)
        {
            if (!($requestContext['fd'] ?? null))
            {
                return JsonObjectParser::class;
            }

            return ConnectContext::get('httpRouteResult')->routeItem->wsConfig->parserClass ?? JsonObjectParser::class;
        }
        elseif ($server instanceof \Imi\Swoole\Server\TcpServer\Server || $server instanceof \Imi\Swoole\Server\UdpServer\Server)
        {
            return $server->getConfig()['dataParser'] ?? JsonObjectParser::class;
        }
        else
        {
            return JsonObjectParser::class;
        }
    }
}
