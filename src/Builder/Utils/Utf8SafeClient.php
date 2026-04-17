<?php

namespace ManticoreLaravel\Builder\Utils;

use Manticoresearch\Client;
use Manticoresearch\Endpoints\Sql;
use Manticoresearch\ResultSet;

/**
 * A Manticore Client subclass that forces UTF-8 safe JSON decoding on all responses.
 *
 * We use variadic `...$params` to remain compatible with the parent Client::sql() signature
 * regardless of which version of manticoresearch-php is installed. The parent's signature
 * varies across minor versions; variadic is always a valid override.
 */
final class Utf8SafeClient extends Client
{
    /**
     * Execute a SQL query against Manticore.
     *
     * Accepts two calling conventions (mirrors the parent Client::sql() interface):
     *
     *   1. String shorthand:  sql("SELECT * FROM t", true, false)
     *      - $params[0]: raw SQL string
     *      - $params[1]: bool $obj — when true, return a ResultSet; otherwise return array
     *      - $params[2]: bool $rawMode — when true, set endpoint mode to 'raw'
     *
     *   2. Array form:        sql(['body' => ['query' => '...'], 'obj' => true])
     *
     * @return ResultSet|array
     */
    public function sql(mixed ...$params): mixed
    {
        // Normalise to a params array
        if (isset($params[0]) && is_string($params[0])) {
            $normalized = [
                'body' => ['query' => $params[0]],
                'obj'  => isset($params[1]) && $params[1] === true,
                'mode' => isset($params[2]) && $params[2] === true ? 'raw' : '',
            ];
        } else {
            $normalized = is_array($params[0] ?? null) ? $params[0] : [];
        }

        $endpoint = new Sql($normalized);

        if (!empty($normalized['mode'])) {
            $endpoint->setMode($normalized['mode']);
        }

        $response = $this->request($endpoint, ['responseClass' => Utf8SafeResponse::class]);

        if (!empty($normalized['obj'])) {
            return new ResultSet($response);
        }

        return $response->getResponse();
    }
}
