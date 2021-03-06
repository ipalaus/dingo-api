<?php

namespace Dingo\Api\Tests;

use Mockery;
use PHPUnit_Framework_TestCase;

class DispatcherTest extends PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        $this->router = new \Dingo\Api\Routing\Router(new \Illuminate\Events\Dispatcher);
        $this->router->setDefaultVersion('v1');
        $this->router->setVendor('testing');

        $this->request = \Illuminate\Http\Request::create('/', 'GET');
        $this->auth = new \Dingo\Api\Auth\Authenticator($this->router, new \Illuminate\Container\Container, []);

        $this->dispatcher = new \Dingo\Api\Dispatcher(
            $this->request,
            new \Illuminate\Routing\UrlGenerator(new \Illuminate\Routing\RouteCollection, $this->request),
            $this->router,
            $this->auth
        );
    }


    public function tearDown()
    {
        Mockery::close();
    }


    public function testInternalRequests()
    {
        $this->router->api(['version' => 'v1'], function () {
            $this->router->get('test', function () {
                return 'foo';
            });

            $this->router->post('test', function () {
                return 'bar';
            });

            $this->router->put('test', function () {
                return 'baz';
            });

            $this->router->patch('test', function () {
                return 'yin';
            });

            $this->router->delete('test', function () {
                return 'yang';
            });
        });

        $this->assertEquals('foo', $this->dispatcher->get('test'));
        $this->assertEquals('bar', $this->dispatcher->post('test'));
        $this->assertEquals('baz', $this->dispatcher->put('test'));
        $this->assertEquals('yin', $this->dispatcher->patch('test'));
        $this->assertEquals('yang', $this->dispatcher->delete('test'));
    }


    public function testInternalRequestWithVersionAndParameters()
    {
        $this->router->api(['version' => 'v1'], function()
        {
            $this->router->get('test', function(){ return 'test'; });
        });

        $this->assertEquals('test', $this->dispatcher->version('v1')->with(['foo' => 'bar'])->get('test'));
    }


    public function testInternalRequestWithPrefix()
    {
        $this->router->api(['version' => 'v1', 'prefix' => 'baz'], function () {
            $this->router->get('test', function() {
                return 'test';
            });
        });

        $this->assertEquals('test', $this->dispatcher->get('test'));
    }


    public function testInternalRequestWithDomain()
    {
        $this->router->api(['version' => 'v1', 'domain' => 'foo.bar'], function() {
            $this->router->get('test', function () {
                return 'test';
            });
        });

        $this->assertEquals('test', $this->dispatcher->get('test'));
    }


    /**
     * @expectedException Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function testInternalRequestThrowsException()
    {
        $this->router->api(['version' => 'v1'], function () {
            //
        });

        $this->dispatcher->get('test');
    }


    /**
     * @expectedException Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function testInternalRequestThrowsExceptionWhenResponseIsNotOkay()
    {
        $this->router->api(['version' => 'v1'], function () {
            $this->router->get('test', function () {
                return new \Illuminate\Http\Response('test', 401);
            });
        });

        $this->dispatcher->get('test');
    }


    /**
     * @expectedException \RuntimeException
     */
    public function testPretendingToBeUserWithInvalidParameterThrowsException()
    {
        $this->dispatcher->be('foo');
    }


    public function testPretendingToBeUserForSingleRequest()
    {
        $user = Mockery::mock('Illuminate\Database\Eloquent\Model');

        $this->router->api(['version' => 'v1'], function () use ($user) {
            $this->router->get('test', function () use ($user) {
                $this->assertEquals($user, $this->auth->user());
            });
        });

        $this->dispatcher->be($user)->once()->get('test');
    }


    public function testInternalRequestUsingRouteName()
    {
        $this->router->api(['version' => 'v1'], function () {
            $this->router->get('test', ['as' => 'test', function () {
                return 'foo';
            }]);

            $this->router->get('test/{foo}', ['as' => 'parameters', function ($parameter) {
                return $parameter;
            }]);
        });

        $this->assertEquals('foo', $this->dispatcher->route('test'));
        $this->assertEquals('bar', $this->dispatcher->route('parameters', 'bar'));
    }


    public function testInternalRequestUsingControllerAction()
    {
        $this->router->api(['version' => 'v1'], function () {
            $this->router->get('foo', 'Dingo\Api\Tests\Stubs\InternalControllerDispatchingStub@index');
        });

        $this->assertEquals('foo', $this->dispatcher->action('Dingo\Api\Tests\Stubs\InternalControllerDispatchingStub@index'));
    }


    public function testInternalRequestUsingRouteNameAndControllerAction()
    {
        $this->router->api(['version' => 'v1', 'prefix' => 'api'], function ()
        {
            $this->router->get('foo', ['as' => 'foo', function () { return 'foo'; }]);
            $this->router->get('bar', 'Dingo\Api\Tests\Stubs\InternalControllerDispatchingStub@index');
        });

        $this->assertEquals('foo', $this->dispatcher->route('foo'));
        $this->assertEquals('foo', $this->dispatcher->action('Dingo\Api\Tests\Stubs\InternalControllerDispatchingStub@index'));
    }


    public function testInternalRequestWithMultipleVersionsCallsCorrectVersion()
    {
        $this->router->api(['version' => 'v1'], function () {
            $this->router->get('foo', function () {
                return 'foo';
            });
        });

        $this->router->api(['version' => ['v2', 'v3']], function () {
            $this->router->get('foo', function() {
                return 'bar';
            });
        });

        $this->assertEquals('foo', $this->dispatcher->version('v1')->get('foo'));
        $this->assertEquals('bar', $this->dispatcher->version('v2')->get('foo'));
        $this->assertEquals('bar', $this->dispatcher->version('v3')->get('foo'));
    }


    public function testInternalRequestWithNestedInternalRequest()
    {
        $this->router->api(['version' => 'v1'], function () {
            $this->router->get('foo', function () {
                return 'foo'.$this->dispatcher->version('v2')->get('foo');
            });
        });

        $this->router->api(['version' => 'v2'], function () {
            $this->router->get('foo', function () {
                return 'bar'.$this->dispatcher->version('v3')->get('foo');
            });
        });

        $this->router->api(['version' => 'v3'], function () {
            $this->router->get('foo', function () {
                return 'baz';
            });
        });

        $this->assertEquals('foobarbaz', $this->dispatcher->get('foo'));
    }


    public function testRequestStackIsMaintained()
    {
        $this->router->api(['version' => 'v1', 'prefix' => 'api'], function () {
            $this->router->post('foo', function () {
                $this->assertEquals('bar', $this->router->getCurrentRequest()->input('foo'));
                $this->dispatcher->with(['foo' => 'baz'])->post('bar');
                $this->assertEquals('bar', $this->router->getCurrentRequest()->input('foo'));
            });

            $this->router->post('bar', function () {
                $this->assertEquals('baz', $this->router->getCurrentRequest()->input('foo'));
                $this->dispatcher->with(['foo' => 'bazinga'])->post('baz');
                $this->assertEquals('baz', $this->router->getCurrentRequest()->input('foo'));
            });

            $this->router->post('baz', function () {
                $this->assertEquals('bazinga', $this->router->getCurrentRequest()->input('foo'));
            });
        });
        
        $this->dispatcher->with(['foo' => 'bar'])->post('foo');
    }


    public function testRouteStackIsMaintained()
    {
        $this->router->api(['version' => 'v1'], function () {
            $this->router->post('foo', ['as' => 'foo', function() {
                $this->assertEquals('foo', $this->router->currentRouteName());
                $this->dispatcher->post('bar');
                $this->assertEquals('foo', $this->router->currentRouteName());
            }]);

            $this->router->post('bar', ['as' => 'bar', function () {
                $this->assertEquals('bar', $this->router->currentRouteName());
                $this->dispatcher->post('baz');
                $this->assertEquals('bar', $this->router->currentRouteName());
            }]);

            $this->router->post('baz', ['as' => 'bazinga', function () {
                $this->assertEquals('bazinga', $this->router->currentRouteName());
            }]);
        });
        
        $this->dispatcher->post('foo');
    }
}
