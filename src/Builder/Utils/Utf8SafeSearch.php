<?php

namespace ManticoreLaravel\Builder\Utils;

use Manticoresearch\Endpoints\Search as EndpointsSearch;
use Manticoresearch\ResultSet;
use Manticoresearch\Search;

class Utf8SafeSearch extends Search
{
    public function get(): ResultSet
    {
        $this->body = $this->compile();

        if (!isset($this->body['query'])) {
            $this->body['query'] = ['match_all' => new \stdClass()];
        }

        $endpoint = new EndpointsSearch(['body' => $this->body]);

        $resp = $this->client->request($endpoint, [
            'responseClass' => Utf8SafeResponse::class
        ]);

        return new ResultSet($resp);
    }
}
