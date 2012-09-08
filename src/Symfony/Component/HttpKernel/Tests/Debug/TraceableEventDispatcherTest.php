<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\Tests\Debug;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\HttpKernel\Debug\TraceableEventDispatcher;
use Symfony\Component\HttpKernel\Debug\Stopwatch;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TraceableEventDispatcherTest extends \PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        if (!class_exists('Symfony\Component\EventDispatcher\EventDispatcher')) {
            $this->markTestSkipped('The "EventDispatcher" component is not available');
        }

        if (!class_exists('Symfony\Component\HttpFoundation\Request')) {
            $this->markTestSkipped('The "HttpFoundation" component is not available');
        }
    }

    public function testAddRemoveListener()
    {
        $dispatcher = new EventDispatcher();
        $tdispatcher = new TraceableEventDispatcher($dispatcher, new Stopwatch());

        $tdispatcher->addListener('foo', $listener = function () { ; });
        $listeners = $dispatcher->getListeners('foo');
        $this->assertCount(1, $listeners);
        $this->assertSame($listener, $listeners[0]);

        $tdispatcher->removeListener('foo', $listener);
        $this->assertCount(0, $dispatcher->getListeners('foo'));
    }

    public function testGetListeners()
    {
        $dispatcher = new EventDispatcher();
        $tdispatcher = new TraceableEventDispatcher($dispatcher, new Stopwatch());

        $tdispatcher->addListener('foo', $listener = function () { ; });
        $this->assertSame($dispatcher->getListeners('foo'), $tdispatcher->getListeners('foo'));
    }

    public function testHasListeners()
    {
        $dispatcher = new EventDispatcher();
        $tdispatcher = new TraceableEventDispatcher($dispatcher, new Stopwatch());

        $this->assertFalse($dispatcher->hasListeners('foo'));
        $this->assertFalse($tdispatcher->hasListeners('foo'));

        $tdispatcher->addListener('foo', $listener = function () { ; });
        $this->assertTrue($dispatcher->hasListeners('foo'));
        $this->assertTrue($tdispatcher->hasListeners('foo'));
    }

    public function testAddRemoveSubscriber()
    {
        $dispatcher = new EventDispatcher();
        $tdispatcher = new TraceableEventDispatcher($dispatcher, new Stopwatch());

        $subscriber = new EventSubscriber();

        $tdispatcher->addSubscriber($subscriber);
        $listeners = $dispatcher->getListeners('foo');
        $this->assertCount(1, $listeners);
        $this->assertSame(array($subscriber, 'call'), $listeners[0]);

        $tdispatcher->removeSubscriber($subscriber);
        $this->assertCount(0, $dispatcher->getListeners('foo'));
    }

    public function testGetCalledListeners()
    {
        $dispatcher = new EventDispatcher();
        $tdispatcher = new TraceableEventDispatcher($dispatcher, new Stopwatch());
        $tdispatcher->addListener('foo', $listener = function () { ; });

        $this->assertEquals(array(), $tdispatcher->getCalledListeners());
        $this->assertEquals(array('foo.closure' => array('event' => 'foo', 'type' => 'Closure', 'pretty' => 'closure')), $tdispatcher->getNotCalledListeners());

        $tdispatcher->dispatch('foo');

        $this->assertEquals(array('foo.closure' => array('event' => 'foo', 'type' => 'Closure', 'pretty' => 'closure')), $tdispatcher->getCalledListeners());
        $this->assertEquals(array(), $tdispatcher->getNotCalledListeners());
    }

    public function testLogger()
    {
        $logger = $this->getMock('Symfony\Component\HttpKernel\Log\LoggerInterface');

        $dispatcher = new EventDispatcher();
        $tdispatcher = new TraceableEventDispatcher($dispatcher, new Stopwatch(), $logger);
        $tdispatcher->addListener('foo', $listener1 = function () { ; });
        $tdispatcher->addListener('foo', $listener2 = function () { ; });

        $logger->expects($this->at(0))->method('debug')->with("Notified event \"foo\" to listener \"closure\".");
        $logger->expects($this->at(1))->method('debug')->with("Notified event \"foo\" to listener \"closure\".");

        $tdispatcher->dispatch('foo');
    }

    public function testLoggerWithStoppedEvent()
    {
        $logger = $this->getMock('Symfony\Component\HttpKernel\Log\LoggerInterface');

        $dispatcher = new EventDispatcher();
        $tdispatcher = new TraceableEventDispatcher($dispatcher, new Stopwatch(), $logger);
        $tdispatcher->addListener('foo', $listener1 = function (Event $event) { $event->stopPropagation(); });
        $tdispatcher->addListener('foo', $listener2 = function () { ; });

        $logger->expects($this->at(0))->method('debug')->with("Notified event \"foo\" to listener \"closure\".");
        $logger->expects($this->at(1))->method('debug')->with("Listener \"closure\" stopped propagation of the event \"foo\".");
        $logger->expects($this->at(2))->method('debug')->with("Listener \"closure\" was not called for event \"foo\".");

        $tdispatcher->dispatch('foo');
    }

    public function testDispatchCallListeners()
    {
        $called = array();

        $dispatcher = new EventDispatcher();
        $tdispatcher = new TraceableEventDispatcher($dispatcher, new Stopwatch());
        $tdispatcher->addListener('foo', $listener1 = function () use (&$called) { $called[] = 'foo1'; });
        $tdispatcher->addListener('foo', $listener2 = function () use (&$called) { $called[] = 'foo2'; });

        $tdispatcher->dispatch('foo');

        $this->assertEquals(array('foo1', 'foo2'), $called);
    }

    public function testStopwatchSections()
    {
        $dispatcher = new TraceableEventDispatcher(new EventDispatcher(), $stopwatch = new Stopwatch());
        $kernel = $this->getHttpKernel($dispatcher, function () { return new Response(); });
        $request = Request::create('/');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $events = $stopwatch->getSectionEvents($response->headers->get('X-Debug-Token'));
        $this->assertEquals(array(
            '__section__',
            'kernel.request',
            'kernel.request.loading',
            'kernel.controller',
            'kernel.controller.loading',
            'controller',
            'kernel.response',
            'kernel.response.loading',
            'kernel.terminate',
            'kernel.terminate.loading',
        ), array_keys($events));
    }

    protected function getHttpKernel($dispatcher, $controller)
    {
        $resolver = $this->getMock('Symfony\Component\HttpKernel\Controller\ControllerResolverInterface');
        $resolver->expects($this->once())->method('getController')->will($this->returnValue($controller));
        $resolver->expects($this->once())->method('getArguments')->will($this->returnValue(array()));

        return new HttpKernel($dispatcher, $resolver);
    }
}

class EventSubscriber implements EventSubscriberInterface
{
    static public function getSubscribedEvents()
    {
        return array('foo' => 'call');
    }
}