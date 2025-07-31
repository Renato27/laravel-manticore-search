<?php

namespace ManticoreLaravel\Builder\Utils;

use Manticoresearch\Response;

class Utf8SafeResponse extends Response
{
    public function getResponse(): array
    {
        $data = $this->string;
        
        if (!mb_check_encoding($data, 'UTF-8')) {
            $encoding = mb_detect_encoding($data, ['UTF-8', 'ISO-8859-1', 'ISO-8859-15'], true);
            $data = mb_convert_encoding($data, 'UTF-8', $encoding);
        }

        $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $data);

        $json = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('UTF8-safe decode failed: ' . json_last_error_msg());
        }
        
        return $json;
    }
}
