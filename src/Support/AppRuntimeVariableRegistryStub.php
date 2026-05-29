<?php

namespace ESolution\DataSources\Support;

use RuntimeException;

class AppRuntimeVariableRegistryStub
{
    public function path(): string
    {
        return __DIR__ . '/../../stubs/AppRuntimeVariableRegistry.stub';
    }

    public function contents(): string
    {
        $contents = file_get_contents($this->path());

        if ($contents === false) {
            throw new RuntimeException('Unable to read the AppRuntimeVariableRegistry stub.');
        }

        return $contents;
    }
}
