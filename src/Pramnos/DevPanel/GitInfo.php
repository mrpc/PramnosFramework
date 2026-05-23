<?php

declare(strict_types=1);

namespace Pramnos\DevPanel;

/**
 * Git information helper for the DevPanel.
 *
 * Thin alias that re-exports the framework-level GitInfo so DevPanel code
 * can use the DevPanel namespace without coupling to Framework internals.
 *
 * @package PramnosFramework
 * @subpackage DevPanel
 */
class GitInfo extends \Pramnos\Framework\GitInfo
{
}
