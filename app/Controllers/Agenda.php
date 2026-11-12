<?php

namespace App\Controllers;

use App\Services\AuthService;

class Agenda extends BaseController
{
    protected $helpers = ['url', 'form'];

    public function index()
    {
        return view('agenda', [
            'user' => (new AuthService())->current(), 'baseUrl' => rtrim(site_url(), '/') . '/',
            'csrfToken' => csrf_hash(), 'csrfHeader' => csrf_header(), 'csrfName' => csrf_token(),
        ]);
    }
}
