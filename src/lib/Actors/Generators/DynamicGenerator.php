<?php

namespace Dapr\Actors\Generators;

use Dapr\Actors\Internal\InternalProxy;
use Dapr\DaprClient;
use Dapr\Deserialization\IDeserializer;
use Dapr\Serialization\ISerializer;
use DI\Container;
use JetBrains\PhpStorm\Pure;
use LogicException;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Method;

class DynamicGenerator extends GenerateProxy
{

    #[Pure] public function __construct(string $interface, string $dapr_type, Container $container)
    {
        parent::__construct($interface, $dapr_type, $container);
    }

    public function get_proxy(string $id): InternalProxy
    {
        $current_proxy            = new InternalProxy();
        $interface                = ClassType::from($this->interface);
        $methods                  = $this->get_methods($interface);
        $current_proxy->DAPR_TYPE = $this->dapr_type;
        foreach ($methods as $method) {
            $current_proxy->{$method->getName()} = $this->generate_method($method, $id);
        }

        return $current_proxy;
    }

    protected function generate_failure_method(Method $method): callable
    {
        return function () use ($method) {
            throw new LogicException("Cannot call {$method->getName()} from outside the actor.");
        };
    }

    protected function generate_proxy_method(Method $method, string $id): callable
    {
        return function (...$params) use ($method, $id) {
            global $dapr_container;
            $serializer   = $dapr_container->get(ISerializer::class);
            $client       = $dapr_container->get(DaprClient::class);
            $deserializer = $dapr_container->get(IDeserializer::class);
            if ( ! empty($params)) {
                $params = $serializer->as_array($params[0]);
            }

            $result = $client->post(
                $client->get_api_path("/actors/{$this->dapr_type}/$id/method/{$method->getName()}"),
                $serializer->as_array($params)
            );

            $result->data = $deserializer->detect_from_generator_method(
                $method,
                $result->data
            );

            return $result->data;
        };
    }

    protected function generate_get_id(Method $method, string $id): callable
    {
        return function () use ($id) {
            return $id;
        };
    }
}
