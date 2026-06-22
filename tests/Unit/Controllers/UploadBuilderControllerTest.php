<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\UploadBuilderController;
use ESolution\DataSources\Support\UploadConfigResolver;
use PHPUnit\Framework\TestCase;

class UploadBuilderControllerTest extends TestCase
{
    public function test_it_prefixes_endpoint_and_keeps_existing_fields_when_updating(): void
    {
        $controller = new TestableUploadBuilderController();

        $payload = $controller->exposeNormalizePayload([
            'code' => 'product-image',
            'name' => 'Product Image',
            'endpoint' => 'product-image',
            'upload_path' => 'uploads/products',
            'allowed_extensions' => ['jpg', 'png', 'jpg'],
            'middlewares' => ['auth:sanctum', ''],
            'multiple' => false,
            'enabled' => true,
        ]);

        $this->assertSame('upload/product-image', $payload['endpoint']);
        $this->assertSame(['jpg', 'png'], $payload['allowed_extensions']);
        $this->assertSame(['auth:sanctum'], $payload['middlewares']);
        $this->assertFalse($payload['multiple']);
        $this->assertTrue($payload['enabled']);

        $updatePayload = $controller->exposeNormalizePayload([
            'name' => 'Updated Name',
        ], false);

        $this->assertSame(['name' => 'Updated Name'], $updatePayload);
    }
}

class TestableUploadBuilderController extends UploadBuilderController
{
    public function __construct()
    {
        parent::__construct(new UploadConfigResolver());
    }

    public function exposeNormalizePayload(array $payload, bool $isCreate = true): array
    {
        return $this->normalizePayload($payload, $isCreate);
    }
}
