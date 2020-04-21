<?php

namespace Shieldo;

use Http\Adapter\Guzzle6\Client as Guzzle6Adapter;
use Http\Client\Common\Plugin\AuthenticationPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\HttpAsyncClient;
use Http\Message\Authentication\BasicAuth;
use Http\Message\MessageFactory\GuzzleMessageFactory;
use Http\Message\RequestFactory;
use Http\Promise\Promise;
use Psr\Http\Message\ResponseInterface;
use Solarium\Core\Client\Adapter\Guzzle as GuzzleAdapter;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Client\Request;
use Solarium\Core\Client\Response;
use Solarium\Core\Event\Events;
use Solarium\Core\Event\PostExecuteRequest as PostExecuteRequestEvent;
use Solarium\Core\Event\PreExecuteRequest as PreExecuteRequestEvent;
use Solarium\Core\Plugin\AbstractPlugin;
use Solarium\Core\Query\AbstractQuery;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class SolariumAsyncPlugin extends AbstractPlugin
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
     * @var EventDispatcherInterface|null
     */
    private $eventDispatcher;


    /**
     * @param AbstractQuery        $query
     * @param string|Endpoint|null $endpoint
     * @return Promise
     */
    public function queryAsync($query, $endpoint = null)
    {
        $asyncClient = $this->asyncClient ?: new Guzzle6Adapter($this->client->getAdapter()->getGuzzleClient());
        $solariumRequest = $this->client->createRequest($query);
        $method = $solariumRequest->getMethod();
        $endpoint = $this->client->getEndpoint($endpoint);

        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(
                Events::PRE_EXECUTE_REQUEST,
                new PreExecuteRequestEvent($solariumRequest, $endpoint)
            );
        }

        $requestFactory = $this->requestFactory ?: new GuzzleMessageFactory();
        $request = $requestFactory->createRequest(
            $method,
            $endpoint->getBaseUri().$solariumRequest->getUri(),
            $this->getRequestHeaders($solariumRequest),
            $this->getRequestBody($solariumRequest)
        );

        $authData = $endpoint->getAuthentication();
        if (!empty($authData['username']) && !empty($authData['password'])) {
            $authentication = new BasicAuth($authData['username'], $authData['password']);
            $authenticationPlugin = new AuthenticationPlugin($authentication);
            $asyncClient = new PluginClient($asyncClient, [$authenticationPlugin]);
        }


        return $asyncClient->sendAsyncRequest($request)
            ->then(
                function (ResponseInterface $response) use ($solariumRequest, $endpoint) {
                    $responseHeaders = [
                        "HTTP/{$response->getProtocolVersion()} {$response->getStatusCode()} "
                        . $response->getReasonPhrase(),
                    ];

                    foreach ($response->getHeaders() as $key => $value) {
                        $responseHeaders[] = "{$key}: " . implode(', ', $value);
                    }

                    $response = new Response((string) $response->getBody(), $responseHeaders);

                    if ($this->eventDispatcher) {
                        $this->eventDispatcher->dispatch(
                            Events::POST_EXECUTE_REQUEST,
                            new PostExecuteRequestEvent($solariumRequest, $endpoint, $response)
                        );
                    }

                    return $response;
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

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    protected function initPluginType()
    {
        $this->client->setAdapter(GuzzleAdapter::class);
    }

    private function getRequestBody(Request $request)
    {
        if ($request->getMethod() !== 'POST') {
            return null;
        }

        if ($request->getFileUpload()) {
            return fopen($request->getFileUpload(), 'r');
        }

        return $request->getRawData();
    }

    private function getRequestHeaders(Request $request)
    {
        $headers = [];
        foreach ($request->getHeaders() as $headerLine) {
            list($header, $value) = explode(':', $headerLine);
            if ($header = trim($header)) {
                $headers[$header] = trim($value);
            }
        }

        if (!isset($headers['Content-Type'])) {
            if ($request->getMethod() == Request::METHOD_GET) {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=utf-8';
            } else {
                $headers['Content-Type'] = 'application/xml; charset=utf-8';
            }
        }

        return $headers;
    }
}
