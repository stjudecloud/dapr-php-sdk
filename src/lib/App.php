<?php

namespace Dapr;

use Dapr\Actors\ActorConfig;
use Dapr\Actors\ActorRuntime;
use Dapr\Actors\HealthCheck;
use Dapr\Actors\IActor;
use Dapr\Actors\Reminder;
use Dapr\Deserialization\InvokerParameterResolver;
use Dapr\exceptions\Http\NotFound;
use Dapr\PubSub\Subscriptions;
use Dapr\Serialization\ISerializer;
use DI\ContainerBuilder;
use DI\DependencyException;
use DI\FactoryInterface;
use DI\NotFoundException;
use Exception;
use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use Invoker\Exception\InvocationException;
use Invoker\Exception\NotCallableException;
use Invoker\Exception\NotEnoughParametersException;
use Invoker\Invoker;
use Invoker\ParameterResolver\AssociativeArrayResolver;
use Invoker\ParameterResolver\Container\TypeHintContainerResolver;
use Invoker\ParameterResolver\DefaultValueResolver;
use Invoker\ParameterResolver\ResolverChain;
use Invoker\ParameterResolver\TypeHintResolver;
use JetBrains\PhpStorm\Pure;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Class App
 * @package Dapr
 */
class App
{
    /**
     * @var ServerRequestCreator
     */
    protected ServerRequestCreator $creator;

    /**
     * @var RouteCollector
     */
    protected RouteCollector $routeCollector;

    /**
     * App constructor.
     *
     * @param ContainerInterface $container
     * @param FactoryInterface $factory
     * @param ISerializer $serializer
     * @param Psr17Factory $psr17Factory
     * @param LoggerInterface $logger
     */
    #[Pure] public function __construct(
        protected ContainerInterface $container,
        protected FactoryInterface $factory,
        protected ISerializer $serializer,
        protected Psr17Factory $psr17Factory,
        protected LoggerInterface $logger,
    ) {
        $this->routeCollector = new RouteCollector(
            new Std,
            new GroupCountBased
        );

        $this->creator = new ServerRequestCreator(
            $this->psr17Factory, // ServerRequestFactory
            $this->psr17Factory, // UriFactory
            $this->psr17Factory, // UploadedFileFactory
            $this->psr17Factory  // StreamFactory
        );
    }

    /**
     * Create a dapr app
     *
     * @param ContainerInterface|null $container
     * @param callable|null $configure
     *
     * @return App
     * @throws DependencyException
     * @throws NotFoundException
     * @throws Exception
     */
    public static function create(ContainerInterface $container = null, callable $configure = null): App
    {
        if ($container === null) {
            $builder = new ContainerBuilder();
            $builder->addDefinitions(__DIR__.'/../config.php');
            if ($configure !== null) {
                $configure($builder);
            }
            $container = $builder->build();
        }
        $app = $container->make(App::class, ['routeCollector' => null]);
        $container->set(App::class, $app);

        error_reporting(E_ERROR | E_USER_ERROR);
        ini_set("display_errors", 0);
        set_error_handler(
            function ($err_no, $err_str, $err_file, $err_line) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(
                    [
                        'errorCode' => 'Exception',
                        'message'   => (E_WARNING & $err_no ? 'WARNING' : (E_NOTICE & $err_no ? 'NOTICE' : (E_ERROR & $err_no ? 'ERROR' : 'OTHER'))).': '.$err_str,
                        'file'      => $err_file,
                        'line'      => $err_line,
                    ]
                );
                die();
            },
            E_ALL
        );

        return $app;
    }

    /**
     * @param string $route
     * @param callable $callback
     */
    public function post(string $route, callable $callback): void
    {
        $this->routeCollector->addRoute('POST', $route, $callback);
    }

    /**
     * @param string $route
     * @param callable $callback
     */
    public function options(string $route, callable $callback): void
    {
        $this->routeCollector->addRoute('OPTIONS', $route, $callback);
    }

    /**
     * @param string $route
     * @param callable $callback
     */
    public function patch(string $route, callable $callback): void
    {
        $this->routeCollector->addRoute('PATCH', $route, $callback);
    }

    /**
     * @param string $route
     * @param callable $callback
     */
    public function any(string $route, callable $callback): void
    {
        $this->routeCollector->addRoute(['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'], $route, $callback);
    }

    /**
     * Serve the request
     */
    public function start(): void
    {
        $this->add_dapr_routes($this);
        try {
            $request = $this->creator->fromGlobals();
            $response = $this->handleRequest($request);
        } catch (NotFound $exception) {
            $response = $this->psr17Factory->createResponse(404)->withBody(
                $this->psr17Factory->createStream($this->serializer->as_json($exception))
            )->withAddedHeader('Content-Type', 'application/json');
            $this->logger->info('Route threw a NotFound exception, returning 404.', ['exception' => $exception]);
        } catch (Exception $exception) {
            $response = $this->psr17Factory->createResponse(500)->withBody(
                $this->psr17Factory->createStream($this->serializer->as_json($exception))
            )->withHeader('Content-Type', 'application/json');
            $this->logger->critical('Failed due to {exception}', ['exception' => $exception]);
        }
        $emitter = new SapiEmitter();
        $emitter->emit($response);
    }

    /**
     * @param App $app
     */
    public function add_dapr_routes(App $app): void
    {
        /**
         * Actors
         */
        // deactivate an actor
        $app->delete(
            '/actors/{actor_type}/{actor_id}',
            function (
                string $actor_type,
                string $actor_id,
                ActorRuntime $runtime
            ) {
                $runtime->resolve_actor(
                    $actor_type,
                    $actor_id,
                    fn(IActor $actor) => $runtime->deactivate_actor($actor, $actor_type, $actor_id)
                );
            }
        );
        // allow calling an actor
        $app->put(
            '/actors/{actor_type}/{actor_id}/method/{method_name}[/{reminder_name}]',
            function (
                RequestInterface $request,
                ResponseInterface $response,
                string $actor_type,
                string $actor_id,
                string $method_name,
                ?string $reminder_name,
                ActorRuntime $runtime,
            ) {
                $arg = json_decode($request->getBody()->getContents(), true);
                if ($method_name === 'remind') {
                    $runtime->resolve_actor(
                        $actor_type,
                        $actor_id,
                        fn(IActor $actor) => $actor->remind($reminder_name, Reminder::from_api($reminder_name, $arg))
                    );
                } elseif ($method_name === 'timer') {
                    return $runtime->resolve_actor(
                        $actor_type,
                        $actor_id,
                        fn(IActor $actor) => $response->withBody(
                            $this->serialize_as_stream($runtime->do_method($actor, $reminder_name, $arg['data']))
                        )
                    );
                } else {
                    return $runtime->resolve_actor(
                        $actor_type,
                        $actor_id,
                        fn(IActor $actor) => $response->withBody(
                            $this->serialize_as_stream($runtime->do_method($actor, $method_name, $arg))
                        )
                    );
                }

                return $response;
            }
        );
        // handle configuration
        $app->get('/dapr/config', fn(ActorConfig $config) => $config);
        $app->get(
            '/healthz',
            fn(HealthCheck $check, ResponseInterface $response) => $response->withStatus(
                $check->do_health_check() ? 200 : 500
            )
        );
        /**
         * Publish/Subscribe
         */
        $app->get('/dapr/subscribe', fn(Subscriptions $subscriptions) => $subscriptions);
    }

    /**
     * @param string $route
     * @param callable $callback
     */
    public function delete(string $route, callable $callback): void
    {
        $this->routeCollector->addRoute('DELETE', $route, $callback);
    }

    /**
     * @param string $route
     * @param callable $callback
     */
    public function put(string $route, callable $callback): void
    {
        $this->routeCollector->addRoute('PUT', $route, $callback);
    }

    /**
     * @param mixed $data
     *
     * @return StreamInterface
     */
    private function serialize_as_stream(mixed $data): StreamInterface
    {
        return $this->psr17Factory->createStream($this->serializer->as_json($data));
    }

    public function get(string $route, callable $callback): void
    {
        $this->routeCollector->addRoute('GET', $route, $callback);
    }

    /**
     * Creates and handles a request
     *
     * @param RequestInterface $request
     *
     * @return ResponseInterface
     * @throws InvocationException
     * @throws NotCallableException
     * @throws NotEnoughParametersException
     */
    private function handleRequest(RequestInterface $request): ResponseInterface
    {
        $this->logger->debug(
            'Handling request: {method} {uri}',
            ['method' => $request->getMethod(), 'uri' => $request->getUri()]
        );

        $response = $this->psr17Factory->createResponse()->withHeader('Content-Type', 'application/json');

        $this->container->set(RequestInterface::class, $request);
        $this->container->set(ResponseInterface::class, $response);

        $parameters = ['request' => $request, 'response' => $response];

        $dispatcher = new Dispatcher\GroupCountBased($this->routeCollector->getData());

        $route_info = $dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());
        switch ($route_info[0]) {
            case Dispatcher::NOT_FOUND:
            default:
                return $response->withStatus(404);
            case Dispatcher::METHOD_NOT_ALLOWED:
                $allowed_methods = $route_info[1];

                return $response->withStatus(405)->withAddedHeader('Allow', implode(',', $allowed_methods));
            case Dispatcher::FOUND:
                $parameters += $route_info[2];
                $callback   = $route_info[1];

                $actual_response = $response;
                $response        = $this->run($callback, $parameters);

                if ($response instanceof ResponseInterface) {
                    return $response;
                }

                if (is_array($response) && isset($response['code'])) {
                    if (isset($response['code'])) {
                        $actual_response = $actual_response->withStatus($response['code']);
                    }
                    if (isset($response['body'])) {
                        $actual_response = $actual_response->withBody($this->serialize_as_stream($response['body']));
                    }

                    return $actual_response;
                }

                if ($response instanceof DaprResponse) {
                    $actual_response = $actual_response->withStatus($response->code)->withBody(
                        $this->serialize_as_stream($response->data)
                    );

                    foreach ($response->headers as $header => $value) {
                        $actual_response = $actual_response->withHeader($header, $value);
                    }

                    return $actual_response;
                }

                return $actual_response->withBody($this->serialize_as_stream($response));
        }
    }

    /**
     * @param callable $callback
     * @param array $parameters
     *
     * @return mixed
     * @throws InvocationException
     * @throws NotCallableException
     * @throws NotEnoughParametersException
     */
    public function run(callable $callback, array $parameters = []): mixed
    {
        $request = $this->creator->fromGlobals();
        $this->container->set(RequestInterface::class, $request);
        $resolvers = [
            $this->container->get(InvokerParameterResolver::class),
            new AssociativeArrayResolver(),
            new TypeHintResolver(),
            new TypeHintContainerResolver($this->container),
            new DefaultValueResolver(),
        ];

        $invoker = new Invoker(new ResolverChain($resolvers), $this->container);

        return $invoker->call($callback, $parameters);
    }
}
