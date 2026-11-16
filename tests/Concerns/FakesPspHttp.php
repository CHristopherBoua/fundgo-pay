<?php

namespace Tests\Concerns;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Http\Adapter\Guzzle7\Client as GuzzleAdapter;
use Omnipay\Common\Http\Client as OmnipayHttpClient;
use Psr\Http\Message\RequestInterface;

/**
 * Remplace le client HTTP d'Omnipay par des réponses enregistrées et capture les requêtes.
 * La pile reproduit celle du php-http/guzzle7-adapter en production (pas d'exception sur 4xx).
 */
trait FakesPspHttp
{
    /** @var array<int, array{request: RequestInterface}> */
    protected array $pspHistory = [];

    protected function fakePsp(array $responses): void
    {
        $this->pspHistory = [];
        $stack = new HandlerStack(new MockHandler(array_map(
            fn (array $r) => new Response($r[0], ['Content-Type' => 'application/json'], json_encode($r[1])),
            $responses,
        )));
        $stack->push(Middleware::history($this->pspHistory));

        $this->app->instance('omnipay.http_client', new OmnipayHttpClient(new GuzzleAdapter(new GuzzleClient(['handler' => $stack]))));
    }

    protected function pspRequest(int $i): RequestInterface
    {
        return $this->pspHistory[$i]['request'];
    }

    protected function pspJson(int $i): array
    {
        return json_decode((string) $this->pspRequest($i)->getBody(), true) ?? [];
    }

    protected function pspForm(int $i): array
    {
        parse_str((string) $this->pspRequest($i)->getBody(), $out);

        return $out;
    }
}
