<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final readonly class HelpArticle
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $summary,
        public string $icon,
        public string $group,
        public string $audience,
        public ?string $screenRoute = null,
    ) {}

    public function path(): string
    {
        return base_path('docs/kb/'.$this->slug.'.md');
    }

    public function visibleTo(User $user): bool
    {
        return $this->audience === 'all' || HelpCatalogue::isAdministrator($user);
    }

    public function html(): string
    {
        $html = Str::markdown(File::get($this->path()), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $html = (string) preg_replace('/^<h1>.*?<\/h1>\s*/s', '', $html, 1);

        return (string) preg_replace_callback(
            '/href="([^"]+)"/',
            function (array $matches): string {
                $href = html_entity_decode($matches[1], ENT_QUOTES);

                $path = parse_url($href, PHP_URL_PATH);

                if (! is_string($path) || $path === '') {
                    $path = $href;
                }

                if (! str_ends_with(strtolower($path), '.md')) {
                    return $matches[0];
                }

                $slug = basename($path, '.md');

                if (! preg_match('/^[a-z0-9-]+$/', $slug) || ! is_file(base_path('docs/kb/'.$slug.'.md'))) {
                    return $matches[0];
                }

                $url = route('help.show', $slug);
                $fragment = parse_url($href, PHP_URL_FRAGMENT);

                if (is_string($fragment) && $fragment !== '') {
                    $url .= '#'.$fragment;
                }

                return 'href="'.e($url).'"';
            },
            $html,
        );
    }
}
