<?php

namespace App\Filament\Pages;

use Filament\Http\Middleware\Authenticate;
use Filament\Pages\Page;

class CaseStudy extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Why this desk exists';

    protected static string $layout = 'filament-panels::components.layout.base';

    protected string $view = 'filament.pages.case-study';

    /**
     * @var string | array<string>
     */
    protected static string|array $withoutRouteMiddleware = [
        Authenticate::class,
    ];
}
