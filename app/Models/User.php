<?php

namespace App\Models;

use App\Actions\AnonymizeUser;
use App\Enums\UserRole;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\BannedContent;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Authenticatable user with role-based access and linked social accounts.
 */
class User extends Authenticatable implements FilamentUser, HasName, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            $tracked = array_intersect(['role', 'is_banned'], array_keys($user->getChanges()));

            if ($tracked === []) {
                return;
            }

            $scalar = fn ($value) => $value instanceof \BackedEnum ? $value->value : $value;
            $attributes = $old = [];
            foreach ($tracked as $field) {
                $attributes[$field] = $scalar($user->getAttribute($field));
                $old[$field] = $scalar($user->getOriginal($field));
            }

            activity('user')
                ->performedOn($user)
                ->event('updated')
                ->withProperties(['attributes' => $attributes, 'old' => $old])
                ->log('updated');

            if ($user->wasChanged('is_banned') && $user->is_banned) {
                BannedContent::hide($user);
            }
        });
    }

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'display_name',
        'bio',
        'website',
        'avatar_url',
        'notify_replies_email',
        'notify_replies_system',
        'notify_mentions_email',
        'notify_mentions_system',
        'notify_follows_email',
        'notify_follows_system',
        'default_comment_sort',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_banned' => 'boolean',
            'notify_replies_email' => 'boolean',
            'notify_replies_system' => 'boolean',
            'notify_mentions_email' => 'boolean',
            'notify_mentions_system' => 'boolean',
            'notify_follows_email' => 'boolean',
            'notify_follows_system' => 'boolean',
            'last_login_at' => 'datetime',
            'username_changed_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'role' => UserRole::class,
        ];
    }

    public function isStaff(): bool
    {
        return $this->role?->isStaff() ?? false;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isPendingActivation(): bool
    {
        return $this->email_verified_at === null;
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    public function authorName(): string
    {
        if ($this->isAnonymized()) {
            return AnonymizeUser::DISPLAY_NAME;
        }

        return $this->display_name ?: ($this->username ?: 'Anônimo');
    }

    public function isLinkable(): bool
    {
        return filled($this->username) && ! $this->isAnonymized();
    }

    public function usernameChangeAvailableAt(): ?Carbon
    {
        if ($this->username_changed_at === null) {
            return null;
        }

        $next = $this->username_changed_at->copy()->addDays((int) config('orbita.auth.username_change_cooldown_days'));

        return $next->isFuture() ? $next : null;
    }

    public function canChangeUsername(): bool
    {
        return $this->usernameChangeAvailableAt() === null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isStaff() && ! $this->is_banned && ! $this->isAnonymized();
    }

    public function getFilamentName(): string
    {
        return $this->display_name ?: ($this->username ?: (string) $this->email);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function postDraft(): HasOne
    {
        return $this->hasOne(PostDraft::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(UserReaction::class);
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function hasPassword(): bool
    {
        return filled($this->password);
    }

    public function accessMethodsCount(): int
    {
        return ($this->hasPassword() ? 1 : 0) + $this->socialAccounts()->count();
    }

    public function unreadNotificationsCount(): int
    {
        return once(fn (): int => (int) $this->notifications()->where('is_read', false)->count());
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify((new ResetPasswordNotification($token))->onQueue('emails'));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify((new VerifyEmailNotification)->onQueue('emails'));
    }
}
