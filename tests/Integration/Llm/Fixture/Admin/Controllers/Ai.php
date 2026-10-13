<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm\Fixture\Admin\Controllers;

/** An application's AI screen, at its own floor — as GlideDay's sets 98. */
class Ai extends \Pramnos\Application\Controllers\AiController
{
    protected int $requiredUserType = 98;
}
