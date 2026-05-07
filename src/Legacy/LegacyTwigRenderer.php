<?php

namespace App\Legacy;

use Twig\Environment;

class LegacyTwigRenderer
{
    private Environment $twig;

    /** @var array<string, mixed> */
    private array $appContext;

    /**
     * @param array<string, mixed> $appContext
     */
    public function __construct(Environment $twig, array $appContext)
    {
        $this->twig = $twig;
        $this->appContext = $appContext;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, ['legacy_app' => $this->appContext] + $context);
    }
}
