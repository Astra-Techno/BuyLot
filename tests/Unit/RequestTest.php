<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    public function testRequestCanBeCreated(): void
    {
        $request = new Request(
            method: 'GET',
            path: '/api/users',
            query: ['page' => '1'],
            body: [],
            headers: ['Content-Type' => 'application/json'],
        );

        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('/api/users', $request->getPath());
        $this->assertEquals('1', $request->getQuery('page'));
    }

    public function testRequestGetBodyValue(): void
    {
        $request = new Request(
            method: 'POST',
            path: '/api/users',
            query: [],
            body: ['name' => 'John', 'email' => 'john@example.com'],
            headers: [],
        );

        $this->assertEquals('John', $request->getBody('name'));
        $this->assertEquals('john@example.com', $request->getBody('email'));
    }

    public function testRequestGetHeader(): void
    {
        $request = new Request(
            method: 'GET',
            path: '/api/users',
            query: [],
            body: [],
            headers: ['content-type' => 'application/json', 'authorization' => 'Bearer token'],
        );

        $this->assertEquals('application/json', $request->getHeader('Content-Type'));
        $this->assertEquals('Bearer token', $request->getHeader('Authorization'));
    }
}
