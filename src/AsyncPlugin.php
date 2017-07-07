<?php

namespace Shieldo\AsyncPlugin;

use Http\Adapter\Guzzle6\Client as Guzzle6Adapter;
use Http\Client\Common\Plugin\AuthenticationPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\HttpAsyncClient;
use Http\Message\Authentication\BasicAuth;
use Http\Message\MessageFactory\GuzzleMessageFactory;
use Http\Message\RequestFactory;
use Http\Promise\Promise;
use Psr\Http\Message\ResponseInterface;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Client\Response;
use Solarium\Core\Plugin\AbstractPlugin;
use Solarium\Core\Query\AbstractQuery;

class AsyncPlugin extends AbstractPlugin
{
    /**
     * @var HttpAsyncClient
     */
    private $asyncClient;

    /**
     * @var RequestFactory
     */
    private $requestFactory;

    /**
     * @param AbstractQuery        $query
     * @param string|Endpoint|null $endpoint
     * @return Promise
     */
    public function queryAsync($query, $endpoint = null)
    {
        $asyncClient = $this->asyncClient ?: new Guzzle6Adapter($this->client->getAdapter()->getGuzzleClient());
        $request = $this->client->createRequest($query);
        $method = $request->getMethod();
        $endpoint = $this->client->getEndpoint($endpoint);

        $requestFactory = $this->requestFactory ?: new GuzzleMessageFactory();
        $request = $requestFactory->createRequest(
            $method,
            $endpoint->getBaseUri().$request->getUri(),
            $this->getRequestHeaders($request),
            $this->getRequestBody($request)
        );

        $authData = $endpoint->getAuthentication();
        if (!empty($authData['username']) && !empty($authData['password'])) {
            $authentication = new BasicAuth($authData['username'], $authData['password']);
            $authenticationPlugin = new AuthenticationPlugin($authentication);
            $asyncClient = new PluginClient($asyncClient, [$authenticationPlugin]);
        }

        return $asyncClient->sendAsyncRequest($request)
            ->then(
                function (ResponseInterface $response) {
                    $responseHeaders = [
                        "HTTP/{$response->getProtocolVersion()} {$response->getStatusCode()} "
                        . $response->getReasonPhrase(),
                    ];

                    foreach ($response->getHeaders() as $key => $value) {
                        $responseHeaders[] = "{$key}: " . implode(', ', $value);
                    }

                    return new Response((string) $response->getBody(), $responseHeaders);
                }
            );
    }

    public function setAsyncClient(HttpAsyncClient $asyncClient)
    {
        $this->asyncClient = $asyncClient;

        return $this;
    }

    public function setRequestFactory(RequestFactory $requestFactory)
    {
        $this->requestFactory = $requestFactory;

        return $this;
    }
}
