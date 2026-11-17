<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Exception\HttpException;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function testRouterCanMatchGetRequest(): void
    {
        $router = new Router();
        $called = false;

        $router->get('/users', function(Request $req) use (&$called) {
            $called = true;
            return Response::json(['message' => 'Users list']);
        });

        $request = new Request('GET', '/users');
        $response = $router->dispatch($request);

        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRouterCanExtractPathParams(): void
    {
        $router = new Router();
        $userId = null;

        $router->get('/users/{id}', function(Request $req) use (&$userId) {
            $userId = $req->getParam('id');
            return Response::json(['id' => $userId]);
        });

        $request = new Request('GET', '/users/123');
        $response = $router->dispatch($request);

        $this->assertEquals('123', $userId);
    }

    public function testRouterThrows404ForUnmatchedRoute(): void
    {
        $router = new Router();

        $this->expectException(HttpException::class);
        $request = new Request('GET', '/nonexistent');
        $router->dispatch($request);
    }

    public function testRouterMatchesPostRequest(): void
    {
        $router = new Router();
        $called = false;

        $router->post('/users', function(Request $req) use (&$called) {
            $called = true;
            return Response::created(['id' => 1]);
        });

        $request = new Request('POST', '/users', body: ['name' => 'John']);
        $response = $router->dispatch($request);

        $this->assertTrue($called);
        $this->assertEquals(201, $response->getStatusCode());
    }
}
