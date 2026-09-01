<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final readonly class HelpArticle
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $summary,
        public string $icon,
        public string $group,
        public string $audience,
        /** @var list<string> */
        public array $requiredPermissions = [],
        public ?string $screenRoute = null,
        public ?string $screenPermission = null,
    ) {}

    public function path(): string
    {
        return base_path('docs/kb/'.$this->slug.'.md');
    }

    public function visibleTo(User $user): bool
    {
        return match ($this->audience) {
            'all' => true,
            'owner' => $user->isSystemOwner(),
            'permission' => $user->isSystemOwner() || $this->hasAnyPermission($user, $this->requiredPermissions),
            default => false,
        };
    }

    public function canOpenScreen(User $user): bool
    {
        return $this->screenRoute !== null
            && ($this->screenPermission === null
                || $user->isSystemOwner()
                || $this->hasAnyPermission($user, [$this->screenPermission]));
    }

    /** @param list<string> $permissions */
    private function hasAnyPermission(User $user, array $permissions): bool
    {
        try {
            foreach ($permissions as $permission) {
                if ($user->can($permission)) {
                    return true;
                }
            }
        } catch (PermissionDoesNotExist) {
            return false;
        }

        return false;
    }

    public function html(?User $user = null): string
    {
        $html = Str::markdown(File::get($this->path()), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $html = (string) preg_replace('/^<h1>.*?<\/h1>\s*/s', '', $html, 1);

        $html = (string) preg_replace_callback(
            '/<a href="([^"]+)">(.*?)<\/a>/s',
            function (array $matches) use ($user): string {
                $href = html_entity_decode($matches[1], ENT_QUOTES);
                $label = $matches[2];
                $resolved = $this->resolveHelpHref($href);

                if ($resolved === null) {
                    if (preg_match('#^https?://#i', $href) === 1) {
                        return $matches[0];
                    }

                    return $label;
                }

                if ($user !== null) {
                    $article = app(HelpCatalogue::class)->find($resolved);

                    if ($article === null || ! $article->visibleTo($user)) {
                        return $label;
                    }
                }

                return '<a href="/help/'.$resolved.'">'.$label.'</a>';
            },
            $html,
        );

        return $html;
    }

    private function resolveHelpHref(string $href): ?string
    {
        $path = parse_url($href, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            $path = $href;
        }

        $path = ltrim($path, '/');

        if (str_ends_with(strtolower($path), '.md')) {
            $slug = basename($path, '.md');
        } elseif (preg_match('#^help/([a-z0-9-]+)$#', $path, $match) === 1) {
            $slug = $match[1];
        } else {
            return null;
        }

        if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1 || ! is_file(base_path('docs/kb/'.$slug.'.md'))) {
            return null;
        }

        return $slug;
    }
}
