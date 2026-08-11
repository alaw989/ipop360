<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case User = 'user';

    /**
     * Determine whether the role may manage blog posts.
     */
    public function canManageBlog(): bool
    {
        return in_array($this, [self::Admin, self::Editor], true);
    }

    /**
     * Determine whether the role may manage every blog post (vs only their own).
     */
    public function canManageAllBlogPosts(): bool
    {
        return $this === self::Admin;
    }
}
