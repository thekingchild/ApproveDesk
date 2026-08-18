<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationIndicator extends Component
{
    public bool $mobile = false;

    #[On('notifications-changed')]
    public function refreshCount(): void {}

    public function render(): mixed
    {
        return view('livewire.notification-indicator', [
            'unreadCount' => Auth::user()->unreadNotifications()->count(),
        ]);
    }
}
