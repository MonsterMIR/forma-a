<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;

class LangController extends Controller
{
    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
    }

    public function switch(array $params = []): void
    {
        $lang = $this->request->input('lang', 'en');
        Lang::set($lang);

        // Redirect back to previous page or dashboard
        $referer = $_SERVER['HTTP_REFERER'] ?? '/dashboard';
        // Safety: only redirect to same domain
        $parsed = parse_url($referer);
        $safePath = isset($parsed['path']) ? $parsed['path'] : '/dashboard';
        if (!empty($parsed['query'])) {
            $safePath .= '?' . $parsed['query'];
        }

        $this->redirect($safePath);
    }
}
