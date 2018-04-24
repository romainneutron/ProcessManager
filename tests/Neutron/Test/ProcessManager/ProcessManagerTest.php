<?php

namespace Neutron\Test\ProcessManager;

use Neutron\ProcessManager\ProcessManager;
use Neutron\ProcessManager\ManagedProcess;
use Symfony\Component\Process\Process;

class ProcessManagerTest extends ProcessableTestCase
{
    /**
     * @dataProvider provideInvalidConstructionArguments
     */
    public function testInvalidConstructorArguments($logger, $parallel, $timeoutStrategy, $failureStrategy, $errorMsg)
    {
        $this->expectException('\InvalidArgumentException');
        $this->expectExceptionMessage($errorMsg);
        new ProcessManager($logger, $parallel, $timeoutStrategy, $failureStrategy);
    }

    public function provideInvalidConstructionArguments()
    {
        $logger = $this->createMock('Psr\Log\LoggerInterface');

        return array(
            array($logger, 24, ProcessManager::STRATEGY_ABORT, 'invalid', 'Invalid strategy.'),
            array($logger, 24, 'invalid', ProcessManager::STRATEGY_ABORT, 'Invalid strategy.'),
            array($logger, -2, ProcessManager::STRATEGY_ABORT, ProcessManager::STRATEGY_ABORT, 'Max parallel processes must be a positive value.'),
            array(null, 0, ProcessManager::STRATEGY_ABORT, ProcessManager::STRATEGY_ABORT, 'Max parallel processes must be a positive value.'),
        );
    }

    public function testConstructorArguments()
    {
        $logger = $this->createMock('Psr\Log\LoggerInterface');
        $manager = new ProcessManager($logger);

        $this->assertSame($logger, $manager->getLogger());
        $this->assertEquals(INF, $manager->getMaxParallelProcesses());
        $this->assertEquals(ProcessManager::STRATEGY_ABORT, $manager->getFailureStrategy());
        $this->assertEquals(ProcessManager::STRATEGY_ABORT, $manager->getTimeoutStrategy());

        $manager = new ProcessManager(null, 24, ProcessManager::STRATEGY_IGNORE, ProcessManager::STRATEGY_RETRY);

        $this->assertNull($manager->getLogger());
        $this->assertEquals(24, $manager->getMaxParallelProcesses());
        $this->assertEquals(ProcessManager::STRATEGY_RETRY, $manager->getFailureStrategy());
        $this->assertEquals(ProcessManager::STRATEGY_IGNORE, $manager->getTimeoutStrategy());
    }

    public function testManagedProcessesMustBeStoppedIfRunningOnDestruction()
    {
        $process = new Process('php -r "$n=0;while($n<=400){usleep(10000);$n++;}"');

        $manager = new ProcessManager();
        $manager->add($process);
        $manager->start();

        $this->assertTrue($process->isRunning());
        unset($manager);
        $this->assertFalse($process->isRunning());
        $this->assertTrue($process->hasBeenSignaled());
    }

    public function testLoggerGetterAndSetter()
    {
        $logger = $this->createMock('Psr\Log\LoggerInterface');

        $manager = new ProcessManager();
        $manager->setLogger($logger);
        $this->assertSame($logger, $manager->getLogger());
        $manager->setLogger(null);
        $this->assertNull($manager->getLogger());
    }

    public function testCountableImplementation()
    {
        $manager = new ProcessManager();
        $this->assertCount(0, $manager);
        $manager->add($this->createProcessMock(), 'proc 1');
        $this->assertCount(1, $manager);
        $manager->add($this->createProcessMock(), 'proc 2');
        $this->assertCount(2, $manager);
        $manager->add($this->createProcessMock(), 'proc 3');
        $this->assertCount(3, $manager);
        $manager->remove('proc 3');
        $this->assertCount(2, $manager);
        $manager->remove('proc 2');
        $manager->remove('proc 1');
        $this->assertCount(0, $manager);
    }

    public function testManagedProcessGettersAndSetters()
    {
        $manager = new ProcessManager();
        $managed1 = $this->createManagedProcessMock();
        $managed2 = $this->createManagedProcessMock();
        $this->assertEquals(array(), $manager->getManagedProcesses());
        $manager->setManagedProcesses(array($managed1, $managed2));
        $this->assertSame(array($managed1, $managed2), $manager->getManagedProcesses());
        $manager->setManagedProcesses(array());
        $this->assertEquals(array(), $manager->getManagedProcesses());

        $process = $this->createProcessMock();
        $manager->add($process, 'proc 1');
        $this->assertEquals(array('proc 1' => new ManagedProcess($process)), $manager->getManagedProcesses());
    }

    /**
     * @expectedException Neutron\ProcessManager\Exception\ProcessManagerException
     * @expectedExceptionMessage Can not set processes while running.
     */
    public function testSettingAManagedProcessIsNotPossibleWhileRunning()
    {
        $manager = new ProcessManager();
        $manager->add(new Process('php -r "sleep(1);"'));
        $manager->start();
        $manager->setManagedProcesses(array($this->createManagedProcessMock()));
    }

    public function testVariousStrategies()
    {
        $manager = new ProcessManager();
        $manager->setTimeoutStrategy(ProcessManager::STRATEGY_IGNORE);
        $this->assertEquals(ProcessManager::STRATEGY_IGNORE, $manager->getTimeoutStrategy());
        $this->assertEquals(ProcessManager::STRATEGY_ABORT, $manager->getFailureStrategy());
        $manager->setFailureStrategy(ProcessManager::STRATEGY_RETRY);
        $this->assertEquals(ProcessManager::STRATEGY_IGNORE, $manager->getTimeoutStrategy());
        $this->assertEquals(ProcessManager::STRATEGY_RETRY, $manager->getFailureStrategy());
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Invalid strategy.
     */
    public function testInvalidTimeoutStrategy()
    {
        $manager = new ProcessManager();
        $manager->setTimeoutStrategy('invalid');
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Invalid strategy.
     */
    public function testInvalidFailureStrategy()
    {
        $manager = new ProcessManager();
        $manager->setFailureStrategy('invalid');
    }

    public function testMaxParallelProcessesGetterAndSetters()
    {
        $manager = new ProcessManager();
        $this->assertEquals(INF, $manager->getMaxParallelProcesses());
        $manager->setMaxParallelProcesses(42);
        $this->assertEquals(42, $manager->getMaxParallelProcesses());
        $manager->setMaxParallelProcesses(null);
        $this->assertEquals(INF, $manager->getMaxParallelProcesses());
    }

    /**
     * @dataProvider  provideInvalidMaximumParallelProcesses
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Max parallel processes must be a positive value.
     */
    public function testInvalidMaxParallelProcesses($maximum)
    {
        $manager = new ProcessManager();
        $manager->setMaxParallelProcesses($maximum);
    }

    public function provideInvalidMaximumParallelProcesses()
    {
        return array(array(-3), array(0));
    }

    public function testHas()
    {
        $manager = new ProcessManager();
        $this->assertFalse($manager->has('proc 1'));
        $this->assertFalse($manager->has('proc 2'));
        $manager->add($this->createProcessMock(), 'proc 1');
        $this->assertTrue($manager->has('proc 1'));
        $this->assertFalse($manager->has('proc 2'));
        $manager->add($this->createProcessMock(), 'proc 2');
        $this->assertTrue($manager->has('proc 1'));
        $this->assertTrue($manager->has('proc 2'));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage A process named proc 1 is already attached.
     */
    public function testAddTwiceAProcessWithTheSameNameThrowsAnException()
    {
        $manager = new ProcessManager();
        $manager->add($this->createProcessMock(), 'proc 1');
        $manager->add($this->createProcessMock(), 'proc 1');
    }

    public function testRemovingAProcessStopsItAndRemovesIt()
    {
        $process = $this->createProcessMock();

        $manager = new ProcessManager();
        $manager->add($process, 'proc 1');

        $timeout = 42;
        $signal = 'signal';
        $process->expects($this->once())
            ->method('stop')
            ->with($timeout, $signal);

        $manager->remove('proc 1', $timeout, $signal);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage No process named proc 2 is attached to the manager.
     */
    public function testRemovingAnUnknownProcessThrowsAnException()
    {
        $manager = new ProcessManager();
        $manager->add($this->createProcessMock(), 'proc 1');
        $manager->remove('proc 2');
    }

    public function testGetAProcess()
    {
        $process = $this->createProcessMock();

        $manager = new ProcessManager();
        $manager->add($process, 'proc 1');

        $this->assertEquals(new ManagedProcess($process), $manager->get('proc 1'));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage No process named proc 2 is attached to the manager.
     */
    public function testGetAnUnknownProcessThrowsAnException()
    {
        $manager = new ProcessManager();
        $manager->add($this->createProcessMock(), 'proc 1');
        $manager->get('proc 2');
    }

    public function testDaemonGetterAndSetters()
    {
        $manager = new ProcessManager();
        $this->assertFalse($manager->isDaemon());
        $manager->setDaemon(true);
        $this->assertTrue($manager->isDaemon());
        $manager->setDaemon(false);
        $this->assertFalse($manager->isDaemon());
    }

    public function testDaemonProcessManagerCanStartWIthoutAttachedProcesses()
    {
        $manager = new ProcessManager();
        $manager->setDaemon(true);
        $manager->start();
        $manager->stop();
    }

    public function testFunctionalParallelTaskShouldRunParallelyWithRun()
    {
        $manager = new ProcessManager();
        $manager->add(new Process('php -r "usleep(500000);"'));
        $manager->add(new Process('php -r "usleep(500000);"'));
        $manager->add(new Process('php -r "usleep(500000);"'));
        $start = microtime(true);
        $manager->run();
        $duration = microtime(true) - $start;
        // margin can be 0.1 second due to process start overhead
        $this->assertLessThan(0.20, abs($duration - 0.5));
    }

    public function testFunctionalNonParallelTaskShouldNoRunParallelyWithRun()
    {
        $manager = new ProcessManager();
        $manager->setMaxParallelProcesses(1);
        $manager->add(new Process('php -r "usleep(500000);"'));
        $manager->add(new Process('php -r "usleep(500000);"'));
        $manager->add(new Process('php -r "usleep(500000);"'));
        $start = microtime(true);
        $manager->run();
        $duration = microtime(true) - $start;
        // wider error margin due to process start overhead
        $this->assertLessThan(0.30, abs($duration - 1.5));
    }

    public function testFunctionalMixedParallelismWithRun()
    {
        $manager = new ProcessManager();
        $manager->setMaxParallelProcesses(2);
        $manager->add(new Process('php -r "usleep(500000);"'));
        $manager->add(new Process('php -r "usleep(500000);"'));
        $manager->add(new Process('php -r "usleep(500000);"'));
        $start = microtime(true);
        $manager->run();
        $duration = microtime(true) - $start;
        // wider error margin due to process start overhead
        $this->assertLessThan(0.2, abs($duration - 1));
    }

    public function testFunctionalParallelTaskShouldRunParallelyWithStart()
    {
        $manager = new ProcessManager();
        $manager->add(new Process('php -r "usleep(500000);"'));
        $manager->add(new Process('php -r "usleep(500000);"'));
        $manager->add(new Process('php -r "usleep(500000);"'));
        $start = microtime(true);
        $manager->start();
        while ($manager->isRunning()) {
            usleep(10000);
        }
        $duration = microtime(true) - $start;
        // margin can be 0.1 second due to process start overhead
        $this->assertLessThan(0.15, abs($duration - 0.5));
    }

    public function testFunctionalNonParallelTaskShouldNoRunParallelyWithStart()
    {
        $manager = new ProcessManager();
        $manager->setMaxParallelProcesses(1);
        $manager->add(new Process('php -r "usleep(500000);"'));
        $manager->add(new Process('php -r "usleep(500000);"'));
        $manager->add(new Process('php -r "usleep(500000);"'));
        $start = microtime(true);
        $manager->start();
        while ($manager->isRunning()) {
            usleep(10000);
        }
        $duration = microtime(true) - $start;
        // wider error margin due to process start overhead
        $this->assertLessThan(0.30, abs($duration - 1.5));
    }

    public function testFunctionalMixedParallelismWithStart()
    {
        $manager = new ProcessManager();
        $manager->setMaxParallelProcesses(2);
        $manager->add(new Process('php -r "usleep(500000);"'));
        $manager->add(new Process('php -r "usleep(500000);"'));
        $manager->add(new Process('php -r "usleep(500000);"'));
        $start = microtime(true);
        $manager->start();
        while ($manager->isRunning()) {
            usleep(10000);
        }
        $duration = microtime(true) - $start;
        // wider error margin due to process start overhead
        $this->assertLessThan(0.2, abs($duration - 1));
    }

    public function testFunctionalWaitWaitsForTheEndOfProcess()
    {
        $manager = new ProcessManager();
        $manager->add(new Process('php -r "usleep(500000);"'));
        $start = microtime(true);
        $manager->start()
            ->wait();
        $duration = microtime(true) - $start;
        $this->assertLessThan(0.15, abs($duration - 0.5));
    }

    public function testGetExceptionIfAProcessTimeOutWithAbortStrategy()
    {
        $process = new Process('php -r "sleep(1);"');
        $process->setTimeout(0.1);

        $manager = new ProcessManager();
        $manager->add(new Process('php -r "echo(\'hello\');"'));
        $manager->add(new Process('php -r "echo(\'hello\');"'));
        $manager->add($process);
        $this->expectException('Neutron\ProcessManager\Exception\ProcessManagerException');
        $this->expectExceptionMessage('Managed process timed-out.');
        $manager->run();
        $this->assertFalse($manager->isSuccessful());
    }

    public function testIsNotSuccessfulIfAProcessTimeOutWithIgnoreStrategy()
    {
        $process = new Process('php -r "$n = 0; while ($n<=200) {usleep(100000);$n++;}"');
        $process->setTimeout(0.1);

        $manager = new ProcessManager();
        $manager->setTimeoutStrategy(ProcessManager::STRATEGY_IGNORE);
        $manager->add($process);
        $manager->run();
        $this->assertFalse($manager->isSuccessful());
    }

    public function testIsNotSuccessfulIfAProcessFails()
    {
        $process = new Process('php -r "not valid php"');

        $manager = new ProcessManager();
        $manager->add($process);
        $manager->run();
        $this->assertFalse($manager->isSuccessful());
    }

    public function testIsNotSuccessfulIfAProcessFailsWithIgnoreStrategy()
    {
        $process = new Process('php -r "not valid php"');

        $manager = new ProcessManager();
        $manager->setTimeoutStrategy(ProcessManager::STRATEGY_IGNORE);
        $manager->add($process);
        $manager->run();
        $this->assertFalse($manager->isSuccessful());
    }

    public function testIsNotSuccessfulIfAProcessFailsWithAbortStrategy()
    {
        $process = new Process('php -r "not valid php"');

        $manager = new ProcessManager();
        $manager->setTimeoutStrategy(ProcessManager::STRATEGY_ABORT);
        $manager->add($process);
        $manager->run();
        $this->assertFalse($manager->isSuccessful());
    }

    public function testIsSuccessfulIfProcessesSucceeded()
    {
        $manager = new ProcessManager();
        $manager->add(new Process('php -r "echo(\'hello\');"'));
        $manager->add(new Process('php -r "echo(\'hello\');"'));
        $manager->add(new Process('php -r "echo(\'hello\');"'));
        $manager->run();
        $this->assertTrue($manager->isSuccessful());
    }

    public function testIsSuccessfulIfAProcessTimeOutWithRetryStrategyAndFinallySucceed()
    {
        $timeout = 0.3;
        $process = new Process('php -r "usleep(200000);"');
        $process->setTimeout($timeout);

        $manager = new ProcessManager();
        $manager->setTimeoutStrategy(ProcessManager::STRATEGY_RETRY);
        $manager->add($process, 'test');
        $manager->start();
        $n = 0;
        while ($manager->isRunning()) {
            if ($n >= 2) {
                $timeout = 1;
            }
            usleep(100000);
            $process->setTimeout($timeout);
            $n++;
        }
        $this->assertEquals(0, $manager->get('test')->getFailuresCount());
        $this->assertTrue($manager->isSuccessful());
    }

    public function testDaemonProcessManagerStopsOnStop()
    {
        declare(ticks=1);
        $manager = new ProcessManager();
        $manager->add(new Process('sleep 5'), 'test', INF);
        $manager->setDaemon(true);
        pcntl_signal(SIGALRM, function () use ($manager) {
            $manager->stop();
        });
        pcntl_alarm(1);
        $start = microtime(true);
        $manager->run();
        $this->assertLessThan(0.1, abs((microtime(true) - $start) - 1));
        $this->assertFalse($manager->isRunning());
    }

    public function testDaemonProcessManagerDoesNotStopOnceProcessesFinish()
    {
        declare(ticks=1);
        $manager = new ProcessManager();
        $manager->add(new Process('php -r "usleep(1000)"'), 'test');
        $manager->setDaemon(true);
        $manager->start();
        sleep(1);
        $this->assertTrue($manager->isRunning());
        $manager->stop();
        $this->assertFalse($manager->isRunning());
    }

    public function getOutput($process)
    {
        return $process->get('test-one')->getManagedProcess()->getOutput();
    }

    private function createManagedProcessMock()
    {
        return $this->getMockBuilder('Neutron\ProcessManager\ManagedProcess')
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function createProcess($commandline, $cwd = null, array $env = null, $stdin = null, $timeout = 60, array $options = array())
    {
        $manager = new ProcessManager();
        $manager->add(new Process($commandline, $cwd, $env, $stdin, $timeout, $options), 'test-one');

        return $manager;
    }
}
