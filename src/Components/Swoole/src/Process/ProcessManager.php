<?php

declare(strict_types=1);

namespace Imi\Swoole\Process;

use Imi\App;
use Imi\Bean\BeanFactory;
use Imi\Bean\Scanner;
use Imi\Event\Event;
use Imi\Server\ServerManager;
use Imi\Swoole\Process\Exception\ProcessAlreadyRunException;
use Imi\Swoole\Process\Parser\ProcessParser;
use Imi\Swoole\Server\Contract\ISwooleServer;
use Imi\Swoole\Util\Imi as SwooleImi;
use Imi\Util\File;
use Imi\Util\Imi;
use Imi\Util\Process\ProcessAppContexts;
use Imi\Util\Process\ProcessType;
use Swoole\ExitException;
use Swoole\Process;

/**
 * 进程管理类.
 */
class ProcessManager
{
    /**
     * 锁集合.
     *
     * @var array
     */
    private static array $lockMap = [];

    /**
     * 挂载在管理进程下的进程列表.
     *
     * @var \Swoole\Process[]
     */
    private static array $managerProcesses = [];

    private function __construct()
    {
    }

    /**
     * 创建进程
     * 本方法无法在控制器中使用
     * 返回\Swoole\Process对象实例.
     *
     * @param string      $name
     * @param array       $args
     * @param bool|null   $redirectStdinStdout
     * @param int|null    $pipeType
     * @param string|null $alias
     *
     * @return \Swoole\Process
     */
    public static function create(string $name, array $args = [], ?bool $redirectStdinStdout = null, ?int $pipeType = null, ?string $alias = null): \Swoole\Process
    {
        $processOption = ProcessParser::getInstance()->getProcess($name);
        if (null === $processOption)
        {
            return null;
        }
        if ($processOption['Process']->unique && static::isRunning($name))
        {
            throw new ProcessAlreadyRunException(sprintf('Process %s already run', $name));
        }
        if (null === $redirectStdinStdout)
        {
            $redirectStdinStdout = $processOption['Process']->redirectStdinStdout;
        }
        if (null === $pipeType)
        {
            $pipeType = $processOption['Process']->pipeType;
        }
        $process = new \Swoole\Process(static::getProcessCallable($args, $name, $processOption, $alias), $redirectStdinStdout, $pipeType);

        return $process;
    }

    /**
     * 获取进程回调.
     *
     * @param array       $args
     * @param string      $name
     * @param array       $processOption
     * @param string|null $alias
     *
     * @return callable
     */
    public static function getProcessCallable(array $args, string $name, array $processOption, ?string $alias = null): callable
    {
        return function (\Swoole\Process $swooleProcess) use ($args, $name, $processOption, $alias) {
            App::set(ProcessAppContexts::PROCESS_TYPE, ProcessType::PROCESS, true);
            App::set(ProcessAppContexts::PROCESS_NAME, $name, true);
            // 设置进程名称
            $processName = $name;
            if ($alias)
            {
                $processName .= '#' . $processName;
            }
            SwooleImi::setProcessName('process', [
                'processName'   => $processName,
            ]);
            // 随机数播种
            mt_srand();
            $exitCode = 0;
            $callable = function () use ($swooleProcess, $args, $name, $processOption, &$exitCode) {
                try
                {
                    if ($processOption['Process']->unique && !static::lockProcess($name))
                    {
                        throw new \RuntimeException('lock process lock file error');
                    }
                    \Imi\Swoole\Util\Process::clearNotInheritableSignalListener();
                    // 加载服务器注解
                    Scanner::scanVendor();
                    Scanner::scanApp();
                    // 进程开始事件
                    Event::trigger('IMI.PROCESS.BEGIN', [
                        'name'      => $name,
                        'process'   => $swooleProcess,
                    ]);
                    // 执行任务
                    $processInstance = BeanFactory::newInstance($processOption['className'], $args);
                    $processInstance->run($swooleProcess);
                    if ($processOption['Process']->unique)
                    {
                        static::unlockProcess($name);
                    }
                }
                catch (ExitException $e)
                {
                    $exitCode = $e->getStatus();
                }
                catch (\Throwable $th)
                {
                    App::getBean('ErrorLog')->onException($th);
                    $exitCode = 255;
                }
                finally
                {
                    // 进程结束事件
                    Event::trigger('IMI.PROCESS.END', [
                        'name'      => $name,
                        'process'   => $swooleProcess,
                    ]);
                }
            };
            if ($processOption['Process']->co)
            {
                // 强制开启进程协程化
                \Co\run($callable);
            }
            else
            {
                $callable();
            }
            if (0 != $exitCode)
            {
                exit($exitCode);
            }
        };
    }

    /**
     * 进程是否已在运行，只有unique为true时有效.
     *
     * @param string $name
     *
     * @return bool
     */
    public static function isRunning(string $name): bool
    {
        $processOption = ProcessParser::getInstance()->getProcess($name);
        if (null === $processOption)
        {
            return false;
        }
        if (!$processOption['Process']->unique)
        {
            return false;
        }
        $fileName = static::getLockFileName($name);
        if (!is_file($fileName))
        {
            return false;
        }
        $fp = fopen($fileName, 'w+');
        if (false === $fp)
        {
            return false;
        }
        if (!flock($fp, \LOCK_EX | \LOCK_NB))
        {
            fclose($fp);

            return true;
        }
        flock($fp, \LOCK_UN);
        fclose($fp);
        unlink($fileName);

        return false;
    }

    /**
     * 运行进程，协程挂起等待进程执行返回
     * 不返回\Swoole\Process对象实例
     * 执行失败返回false，执行成功返回数组，包含了进程退出的状态码、信号、输出内容。
     * array(
     *     'code'   => 0,
     *     'signal' => 0,
     *     'output' => '',
     * );.
     *
     * @param string    $name
     * @param array     $args
     * @param bool|null $redirectStdinStdout
     * @param int|null  $pipeType
     *
     * @return array
     */
    public static function run(string $name, array $args = [], ?bool $redirectStdinStdout = null, ?int $pipeType = null): array
    {
        $cmd = Imi::getImiCmd('process/run', [$name], $args);
        if (null !== $redirectStdinStdout)
        {
            $cmd .= ' --redirectStdinStdout ' . $redirectStdinStdout;
        }
        if (null !== $pipeType)
        {
            $cmd .= ' --pipeType ' . $pipeType;
        }

        return \Swoole\Coroutine::exec($cmd);
    }

    /**
     * 运行进程，创建一个协程执行进程，无法获取进程执行结果
     * 执行失败返回false，执行成功返回数组，包含了进程退出的状态码、信号、输出内容。
     * array(
     *     'code'   => 0,
     *     'signal' => 0,
     *     'output' => '',
     * );.
     *
     * @param string    $name
     * @param array     $args
     * @param bool|null $redirectStdinStdout
     * @param int|null  $pipeType
     *
     * @return void
     */
    public static function coRun(string $name, array $args = [], ?bool $redirectStdinStdout = null, ?int $pipeType = null)
    {
        go(function () use ($name, $args, $redirectStdinStdout, $pipeType) {
            static::run($name, $args, $redirectStdinStdout, $pipeType);
        });
    }

    /**
     * 挂靠Manager进程运行进程.
     *
     * @param string      $name
     * @param array       $args
     * @param bool|null   $redirectStdinStdout
     * @param int|null    $pipeType
     * @param string|null $alias
     *
     * @return \Swoole\Process|null
     */
    public static function runWithManager(string $name, array $args = [], ?bool $redirectStdinStdout = null, ?int $pipeType = null, ?string $alias = null): ?Process
    {
        if (App::isCoServer())
        {
            $processOption = ProcessParser::getInstance()->getProcess($name);
            if (null === $processOption)
            {
                return null;
            }
            ServerManager::getCoServer()->addProcess(static::getProcessCallable($args, $name, $processOption, $alias));

            return null;
        }
        else
        {
            $process = static::create($name, $args, $redirectStdinStdout, $pipeType, $alias);
            $server = ServerManager::getServer('main', ISwooleServer::class)->getSwooleServer();
            $server->addProcess($process);
            static::$managerProcesses[$name][$alias] = $process;

            return $process;
        }
    }

    /**
     * 获取挂载在管理进程下的进程.
     *
     * @param string      $name
     * @param string|null $alias
     *
     * @return \Swoole\Process|null
     */
    public static function getProcessWithManager(string $name, ?string $alias = null): ?Process
    {
        return static::$managerProcesses[$name][$alias] ?? null;
    }

    /**
     * 锁定进程，实现unique.
     *
     * @param string $name
     *
     * @return bool
     */
    private static function lockProcess(string $name): bool
    {
        $fileName = static::getLockFileName($name);
        $fp = fopen($fileName, 'w+');
        if (false === $fp)
        {
            return false;
        }
        if (!flock($fp, \LOCK_EX | \LOCK_NB))
        {
            fclose($fp);

            return false;
        }
        static::$lockMap[$name] = [
            'fileName'  => $fileName,
            'fp'        => $fp,
        ];

        return true;
    }

    /**
     * 解锁进程，实现unique.
     *
     * @param string $name
     *
     * @return bool
     */
    private static function unlockProcess(string $name): bool
    {
        $lockMap = &static::$lockMap;
        if (!isset($lockMap[$name]))
        {
            return false;
        }
        $lockItem = $lockMap[$name];
        $fp = $lockItem['fp'];
        if (flock($fp, \LOCK_UN) && fclose($fp))
        {
            unlink($lockItem['fileName']);
            unset($lockMap[$name]);

            return true;
        }

        return false;
    }

    /**
     * 获取文件锁的文件名.
     *
     * @param string $name
     *
     * @return string
     */
    private static function getLockFileName(string $name): string
    {
        $path = Imi::getRuntimePath(str_replace('\\', '-', App::getNamespace()), 'processLock');
        if (!is_dir($path))
        {
            File::createDir($path);
        }

        return File::path($path, $name . '.lock');
    }
}
