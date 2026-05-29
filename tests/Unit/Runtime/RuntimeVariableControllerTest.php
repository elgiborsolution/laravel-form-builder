<?php

namespace ESolution\DataSources\Tests\Unit\Runtime;

use ESolution\DataSources\Controllers\RuntimeVariableController;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\TestCase;

class RuntimeVariableControllerTest extends TestCase
{
    public function test_it_returns_exposed_runtime_variables(): void
    {
        $controller = new RuntimeVariableController(new FakeRuntimeVariableRegistry());

        $response = $controller->index();

        $this->assertInstanceOf(JsonResponse::class, $response);

        $payload = json_decode($response->getContent(), true);

        $this->assertIsArray($payload);
        $this->assertSame('app.env', $payload[0]['key']);
        $this->assertSame('auth.company_id', $payload[1]['key']);
        $this->assertSame('auth.id', $payload[3]['key']);
        $this->assertArrayHasKey('type', $payload[0]);
        $this->assertArrayHasKey('description', $payload[0]);
    }
}
