<?php

declare(strict_types=1);

namespace Tomos;

interface SocialProvider
{
    public function name(): string;

    public function publish(string $text, string $articleUrl, array $context = []): SocialPublishResult;
}
