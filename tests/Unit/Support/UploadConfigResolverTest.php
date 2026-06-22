<?php

namespace ESolution\DataSources\Tests\Unit\Support;

use ESolution\DataSources\Support\UploadConfigResolver;
use PHPUnit\Framework\TestCase;

class UploadConfigResolverTest extends TestCase
{
    public function test_it_normalizes_upload_endpoints_with_the_upload_prefix(): void
    {
        $resolver = new UploadConfigResolver();

        $this->assertSame('upload/products', $resolver->normalizeEndpoint('products'));
        $this->assertSame('upload/products', $resolver->normalizeEndpoint('/products/'));
        $this->assertSame('upload/products', $resolver->normalizeEndpoint('upload/products'));
    }
}
