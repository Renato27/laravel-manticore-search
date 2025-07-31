<?php

namespace ManticoreLaravel\Builder\Utils;

use Manticoresearch\Client;
use Manticoresearch\Endpoints\Sql;
use Manticoresearch\ResultSet;

class Utf8SafeClient extends Client
{
    public function sql(...$params) {
		if (is_string($params[0])) {
			$params = [
				'body' => [
					'query' => $params[0],
				],
                'obj' => !empty($params[1]) && is_bool($params[1]) ? true : false,
                'mode' => !empty($params[2]) && is_bool($params[2]) ? 'raw' : '',
			];
		} else {
			$params = $params[0];
		}
		$endpoint = new Sql($params);
		if (isset($params['mode'])) {
			$endpoint->setMode($params['mode']);
			$response = $this->request($endpoint, ['responseClass' => Utf8SafeResponse::class]);
		} else {
			$response = $this->request($endpoint, ['responseClass' => Utf8SafeResponse::class]);
		}
        
        if(isset($params['obj']) && $params['obj'] === true) {
            return new ResultSet($response);
        }
		return $response->getResponse();
	}
}
