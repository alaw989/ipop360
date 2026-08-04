<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use Inertia\Inertia;
use Inertia\Response;

class BlogController extends Controller
{
    public function index(): Response
    {
        $posts = BlogPost::published()
            ->with('author:id,name')
            ->latest('published_at')
            ->paginate(12);

        return Inertia::render('Blog/Index', [
            'posts' => $posts,
        ]);
    }

    public function show(BlogPost $post): Response
    {
        if ($post->status !== 'published' || $post->published_at === null || $post->published_at->gt(now())) {
            abort(404);
        }

        return Inertia::render('Blog/Show', [
            'post' => $post->load('author:id,name'),
        ]);
    }
}
