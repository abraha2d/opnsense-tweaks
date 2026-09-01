<?php

namespace Tests\Integration\Support;

class FakeRoot
{
    public string $root;

    public function __construct(?string $root = null)
    {
        $this->root = test_make_fake_root($root);
    }

    public function path(string $abs): string
    {
        return $this->root . $abs;
    }

    public function put(string $abs, string $content): void
    {
        $dir = dirname($this->path($abs));
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        file_put_contents($this->path($abs), $content);
    }

    public function exists(string $abs): bool
    {
        return file_exists($this->path($abs));
    }

    public function cleanup(): void
    {
        test_cleanup_fake_root($this->root);
    }
}
