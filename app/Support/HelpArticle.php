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

        return (string) preg_replace('/^<h1>.*?<\/h1>\s*/s', '', $html, 1);
    }
}
