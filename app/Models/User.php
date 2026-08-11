<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Determine whether the user is an administrator.
     */
    public function isAdmin(): bool
    {
        return UserRole::tryFrom($this->role) === UserRole::Admin;
    }

    /**
     * Determine whether the user is an editor.
     */
    public function isEditor(): bool
    {
        return UserRole::tryFrom($this->role) === UserRole::Editor;
    }

    /**
     * Determine whether the user may manage blog posts.
     */
    public function canManageBlog(): bool
    {
        return UserRole::tryFrom($this->role)?->canManageBlog() ?? false;
    }

    /**
     * Determine whether the user may manage every blog post (vs only their own).
     */
    public function canManageAllBlogPosts(): bool
    {
        return UserRole::tryFrom($this->role)?->canManageAllBlogPosts() ?? false;
    }

    /**
     * The restaurants that the user has favorited.
     */
    /**
     * @return BelongsToMany<Restaurant, $this>
     */
    public function favorites(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'favorite_restaurant_user')
            ->withTimestamps();
    }
}
