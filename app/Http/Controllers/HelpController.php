<?php

namespace App\Http\Controllers;

use App\Support\HelpArticle;
use App\Support\HelpCatalogue;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HelpController extends Controller
{
    public function index(Request $request, HelpCatalogue $catalogue): View
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $articles = $catalogue->visibleFor($user);

        return view('pages.help.index', [
            'articles' => $articles,
            'groups' => $articles->groupBy(fn (HelpArticle $article): string => $article->group),
            'isAdministrator' => HelpCatalogue::isAdministrator($user),
        ]);
    }

    public function show(Request $request, HelpCatalogue $catalogue, string $article): View
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $item = $catalogue->find($article);

        if ($item === null) {
            throw new NotFoundHttpException;
        }

        abort_unless($item->visibleTo($user), 403);

        return view('pages.help.show', [
            'article' => $item,
            'html' => $item->html($user),
            'siblings' => $catalogue->visibleFor($user)
                ->filter(fn (HelpArticle $article): bool => $article->group === $item->group)
                ->values(),
        ]);
    }
}
