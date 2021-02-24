<?php

declare(strict_types=1);

namespace Imi\Server\UdpServer\Listener;

use Imi\Bean\Annotation\AnnotationManager;
use Imi\Config;
use Imi\Event\EventParam;
use Imi\Event\IEventListener;
use Imi\Main\Helper;
use Imi\RequestContext;
use Imi\Server\Protocol;
use Imi\Server\Route\RouteCallable;
use Imi\Server\Route\TMiddleware;
use Imi\Server\ServerManager;
use Imi\Server\UdpServer\Parser\UdpControllerParser;
use Imi\Server\UdpServer\Route\Annotation\UdpAction;
use Imi\Server\UdpServer\Route\Annotation\UdpMiddleware;
use Imi\Server\UdpServer\Route\Annotation\UdpRoute;
use Imi\Worker;

/**
 * UDP 服务器路由初始化.
 */
class UdpRouteInit implements IEventListener
{
    use TMiddleware;

    /**
     * 事件处理方法.
     *
     * @param EventParam $e
     *
     * @return void
     */
    public function handle(EventParam $e)
    {
        $this->parseAnnotations($e);
        $this->parseConfigs();
    }

    /**
     * 处理注解路由.
     *
     * @return void
     */
    private function parseAnnotations(EventParam $e)
    {
        $controllerParser = UdpControllerParser::getInstance();
        $context = RequestContext::getContext();
        foreach (ServerManager::getServers() as $name => $server)
        {
            if (Protocol::UDP !== $server->getProtocol())
            {
                continue;
            }
            $context['server'] = $server;
            /** @var \Imi\Server\UdpServer\Route\UdpRoute $route */
            $route = $server->getBean('UdpRoute');
            foreach ($controllerParser->getByServer($name) as $className => $classItem)
            {
                /** @var \Imi\Server\UdpServer\Route\Annotation\UdpController $classAnnotation */
                $classAnnotation = $classItem->getAnnotation();
                // 类中间件
                $classMiddlewares = [];
                foreach (AnnotationManager::getClassAnnotations($className, UdpMiddleware::class) ?? [] as $middleware)
                {
                    $classMiddlewares = array_merge($classMiddlewares, $this->getMiddlewares($middleware->middlewares, $name));
                }
                foreach (AnnotationManager::getMethodsAnnotations($className, UdpAction::class) as $methodName => $methodItem)
                {
                    $routes = AnnotationManager::getMethodAnnotations($className, $methodName, UdpRoute::class);
                    if (!isset($routes[0]))
                    {
                        throw new \RuntimeException(sprintf('%s->%s method has no route', $className, $methodName));
                    }
                    // 方法中间件
                    $methodMiddlewares = [];
                    foreach (AnnotationManager::getMethodAnnotations($className, $methodName, UdpMiddleware::class) ?? [] as $middleware)
                    {
                        $methodMiddlewares = array_merge($methodMiddlewares, $this->getMiddlewares($middleware->middlewares, $name));
                    }
                    // 最终中间件
                    $middlewares = array_values(array_unique(array_merge($classMiddlewares, $methodMiddlewares)));

                    foreach ($routes as $routeItem)
                    {
                        $route->addRuleAnnotation($routeItem, new RouteCallable($server->getName(), $className, $methodName), [
                            'middlewares' => $middlewares,
                            'singleton'   => null === $classAnnotation->singleton ? Config::get('@server.' . $name . '.controller.singleton', false) : $classAnnotation->singleton,
                        ]);
                    }
                }
            }
            if (0 === Worker::getWorkerID())
            {
                $route->checkDuplicateRoutes();
            }
            unset($context['server']);
        }
    }

    /**
     * 处理配置文件路由.
     *
     * @return void
     */
    private function parseConfigs()
    {
        $context = RequestContext::getContext();
        foreach (ServerManager::getServers() as $server)
        {
            if (Protocol::UDP !== $server->getProtocol())
            {
                continue;
            }
            $context['server'] = $server;
            $route = $server->getBean('UdpRoute');
            $main = Helper::getMain($server->getConfig()['namespace']);
            if ($main)
            {
                foreach ($main->getConfig()['route'] ?? [] as $routeOption)
                {
                    $routeAnnotation = new UdpRoute($routeOption['route'] ?? []);
                    if (isset($routeOption['callback']))
                    {
                        $callable = $routeOption['callback'];
                    }
                    else
                    {
                        $callable = new RouteCallable($server->getName(), $routeOption['controller'], $routeOption['method']);
                    }
                    $route->addRuleAnnotation($routeAnnotation, $callable, [
                        'middlewares' => $routeOption['middlewares'],
                    ]);
                }
            }
            unset($context['server']);
        }
    }
}