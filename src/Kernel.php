<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * The application: the webhook (public/index.php) and the worker's commands
 * (bin/console). Configured in config/.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Creates the kernel from Symfony Runtime's context (APP_ENV, APP_DEBUG).
     *
     * @param array<mixed> $context
     */
    public static function fromContext(array $context): self
    {
        $environment = $context['APP_ENV'] ?? null;
        if (!is_string($environment) || $environment === '') {
            throw new \RuntimeException('APP_ENV is not set.');
        }

        return new self($environment, (bool) ($context['APP_DEBUG'] ?? false));
    }
}
