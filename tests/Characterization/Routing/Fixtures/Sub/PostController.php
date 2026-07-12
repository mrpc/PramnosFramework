<?php

declare(strict_types=1);

namespace PramnosTest\Routing\Fixtures\Sub;

use Pramnos\Routing\Attributes\Route;

/**
 * Fixture controller in a subdirectory to verify recursive directory scanning.
 */
class PostController
{
    #[Route('/posts/{year}/{slug?}', methods: 'GET', name: 'posts.show')]
    public function show(): string
    {
        return 'post_detail';
    }

    #[Route('/posts', methods: ['GET', 'HEAD'], name: 'posts.index')]
    public function index(): string
    {
        return 'post_list';
    }
}
