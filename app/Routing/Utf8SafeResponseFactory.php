<?php

namespace App\Routing;

use Illuminate\Routing\ResponseFactory;

class Utf8SafeResponseFactory extends ResponseFactory
{
    public function json($data = [], $status = 200, array $headers = [], $options = 0)
    {
        return parent::json($data, $status, $headers, $options | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function jsonp($callback, $data = [], $status = 200, array $headers = [], $options = 0)
    {
        return parent::jsonp($callback, $data, $status, $headers, $options | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
