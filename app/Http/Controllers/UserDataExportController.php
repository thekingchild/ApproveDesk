<?php

namespace App\Http\Controllers;

use App\Actions\Privacy\ExportUserDataAction;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserDataExportController extends Controller
{
    public function __invoke(Request $request, ExportUserDataAction $action): StreamedResponse
    {
        return $action->execute($request->user());
    }
}
