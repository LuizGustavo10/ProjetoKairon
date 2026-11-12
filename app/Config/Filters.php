<?php
namespace Config;
use App\Filters\Authenticated;
use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\SecureHeaders;
use CodeIgniter\Filters\ForceHTTPS;
class Filters extends BaseFilters
{
    public array $aliases = ['auth' => Authenticated::class, 'csrf' => CSRF::class, 'invalidchars' => InvalidChars::class, 'secureheaders' => SecureHeaders::class, 'forcehttps' => ForceHTTPS::class];
    public array $required = ['before' => ['forcehttps'], 'after' => []];
    public array $globals = ['before' => ['invalidchars', 'csrf'], 'after' => ['secureheaders']];
    public array $methods = [];
    public array $filters = [];
}
