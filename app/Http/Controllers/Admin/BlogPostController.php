<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\User;
use App\Services\HtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BlogPostController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $posts = BlogPost::with('author')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status));

        if ($user !== null && ! $user->canManageAllBlogPosts()) {
            $posts->where('author_id', $user->id);
        }

        $posts = $posts->latest()->paginate(20)->withQueryString();

        return Inertia::render('Admin/Blog/Index', [
            'posts' => $posts,
            'filter' => $request->query('status'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Blog/Edit', [
            'post' => null,
        ]);
    }

    public function store(Request $request, HtmlSanitizer $sanitizer): RedirectResponse
    {
        $data = $this->validated($request);

        $data['body'] = $sanitizer->sanitize($data['body']);

        $post = new BlogPost($data);
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }
        assert($user->id >= 0);
        $post->author_id = $user->id;
        $post->save();

        if (($data['status'] ?? null) === 'published') {
            $post->publish();
        }

        return redirect()->route('admin.blog.index')->with('success', 'Blog post created.');
    }

    public function edit(Request $request, BlogPost $post): Response
    {
        $this->authorizePost($request->user(), $post);

        return Inertia::render('Admin/Blog/Edit', [
            'post' => $post->load('author'),
        ]);
    }

    public function update(Request $request, BlogPost $post, HtmlSanitizer $sanitizer): RedirectResponse
    {
        $this->authorizePost($request->user(), $post);

        $data = $this->validated($request);

        $data['body'] = $sanitizer->sanitize($data['body']);

        $wasPublished = $post->status === 'published';

        $post->update($data);

        if (! $wasPublished && ($data['status'] ?? null) === 'published') {
            $post->publish();
        }

        return redirect()->route('admin.blog.edit', $post)->with('success', 'Blog post updated.');
    }

    public function destroy(Request $request, BlogPost $post): RedirectResponse
    {
        $this->authorizePost($request->user(), $post);

        $post->delete();

        return redirect()->route('admin.blog.index')->with('success', 'Blog post deleted.');
    }

    /**
     * Ensure the user may manage the given post.
     *
     * Admins manage all posts; editors manage only their own.
     */
    private function authorizePost(?User $user, BlogPost $post): void
    {
        if ($user !== null && ! $user->canManageAllBlogPosts() && $post->author_id !== $user->id) {
            abort(403);
        }
    }

    /**
     * Validate and normalize the blog post form input.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['required', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'max:100'],
            'body' => ['required', 'string'],
            'featured_image' => ['nullable', 'string', 'max:2048'],
            'status' => ['required', 'in:draft,published'],
        ]);

        return $data;
    }
}
