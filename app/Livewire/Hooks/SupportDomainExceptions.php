<?php

namespace App\Livewire\Hooks;

use App\Exceptions\DomainException;
use Flux\Flux;
use Livewire\ComponentHook;
use Livewire\Livewire;
use Throwable;

/**
 * A domain rule that refuses an action on any Livewire page is shown as a
 * readable message instead of an error page (RV-ARC-19): the message goes to
 * the "domain" error key and a toast. Pages that need the message next to a
 * field keep their own catch; this is the fallback for the rest.
 */
final class SupportDomainExceptions extends ComponentHook
{
    public function exception(Throwable $e, callable $stopPropagation): void
    {
        // Only for actions; a page that cannot even render should still fail loudly.
        if (! $e instanceof DomainException || ! Livewire::isLivewireRequest()) {
            return;
        }

        $this->component->addError('domain', $e->userMessage());
        Flux::toast($e->userMessage(), variant: 'danger');
        $stopPropagation();
    }
}
