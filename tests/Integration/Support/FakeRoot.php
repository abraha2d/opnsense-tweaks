<?php

namespace Tests\Integration\Support;

class FakeRoot
{
    public string $root;
    /** @var string[] list of absolute paths created via put() */
    private array $created = [];
    private bool $isGlobalMount = false;

    public function __construct(?string $root = null)
    {
        $existing = getenv('FAKE_ROOT');
        $this->root = test_make_fake_root($root);
        // If we reused the global mount root (fake-root.sh), track for cleanup
        $this->isGlobalMount = ($existing !== false && $existing !== '' && $this->root === $existing);
    }

    public function path(string $abs): string
    {
        return $this->root . $abs;
    }

    public function put(string $abs, string $content): void
    {
        $dir = dirname($this->path($abs));
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        file_put_contents($this->path($abs), $content);
        $this->created[] = $abs;
    }

    public function exists(string $abs): bool
    {
        return file_exists($this->path($abs));
    }

    public function cleanup(): void
    {
        if ($this->isGlobalMount) {
            // In mount namespace mode, global FAKE_ROOT is bind-mounted to real paths.
            // Only unlink files created by this instance, don't rm the global root.
            foreach ($this->created as $abs) {
                $hostPath = $this->path($abs);
                if (file_exists($hostPath)) {
                    unlink($hostPath);
                }
                if ($hostPath !== $abs && file_exists($abs)) {
                    unlink($abs);
                }
            }
            return;
        }
        test_cleanup_fake_root($this->root);
    }
}
