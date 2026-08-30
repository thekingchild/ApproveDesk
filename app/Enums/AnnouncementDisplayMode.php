<?php

namespace App\Enums;

enum AnnouncementDisplayMode: string
{
    case Sticky = 'sticky';
    case Acknowledge = 'acknowledge';
    case ShowOnce = 'show_once';
}
