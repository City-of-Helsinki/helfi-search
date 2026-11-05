<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A temporary directory per test, removed afterwards.
 */
trait TemporaryDirectoryTrait
{
    private ?string $temporaryDirectory = null;

    protected function temporaryDirectory(): string
    {
        if ($this->temporaryDirectory === null) {
            $this->temporaryDirectory = sys_get_temp_dir() . '/helfi-search-test-' . bin2hex(random_bytes(6));
            mkdir($this->temporaryDirectory, 0775, true);
        }

        return $this->temporaryDirectory;
    }

    #[After]
    protected function removeTemporaryDirectory(): void
    {
        if ($this->temporaryDirectory !== null) {
            // Restore permissions that a test removed, so everything can be
            // deleted.
            (new Filesystem())->chmod($this->temporaryDirectory, 0775, 0000, true);
            (new Filesystem())->remove($this->temporaryDirectory);
            $this->temporaryDirectory = null;
        }
    }
}
