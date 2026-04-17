<?php

use ManticoreLaravel\Builder\Utils\Utf8SafeResponse;

function makeResponse(string $json): Utf8SafeResponse
{
    // Utf8SafeResponse expects $this->string to be populated.
    // We use reflection to set it.
    $resp = new Utf8SafeResponse(200, [], '');
    $ref  = new ReflectionClass($resp);
    $prop = $ref->getProperty('string');
    $prop->setAccessible(true);
    $prop->setValue($resp, $json);
    return $resp;
}

it('decodes valid UTF-8 JSON', function () {
    $resp = makeResponse('{"hits":{"hits":[]}}');
    $data = $resp->getResponse();
    expect($data)->toBeArray()->toHaveKey('hits');
});

it('handles non-UTF-8 encoding by converting', function () {
    $latin1 = json_encode(['name' => mb_convert_encoding('São Paulo', 'ISO-8859-1', 'UTF-8')]);
    // This should not throw
    $resp = makeResponse($latin1);
    expect(fn() => $resp->getResponse())->not->toThrow(\RuntimeException::class);
});

it('strips control characters that break JSON decode', function () {
    $withControl = '{"title":"hello' . chr(0x01) . 'world"}';
    $resp = makeResponse($withControl);
    $data = $resp->getResponse();
    expect($data['title'])->toContain('hello');
});

it('throws RuntimeException on invalid JSON', function () {
    $resp = makeResponse('not valid json {{{');
    $resp->getResponse();
})->throws(\RuntimeException::class, 'UTF8-safe decode failed');
