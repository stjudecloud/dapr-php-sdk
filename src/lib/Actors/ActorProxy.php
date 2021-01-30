<?php

namespace Dapr\Actors;

use Dapr\Actors\Attributes\DaprType;
use Dapr\Actors\Generators\ProxyModes;
use Dapr\Actors\Internal\InternalProxy;
use Dapr\DaprClient;
use Dapr\Deserialization\Deserializer;
use Dapr\Runtime;
use Dapr\Serialization\Serializer;
use LogicException;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\Type;
use ReflectionClass;
use ReflectionMethod;

/**
 * Class ActorProxy
 * @package Dapr
 */
abstract class ActorProxy
{
    public static int $mode = ProxyModes::GENERATED;

    /**
     * Returns an actor proxy
     *
     * @param class-string<IActor> $interface
     * @param mixed $id The id to proxy for
     * @param string|null $override_type Allow overriding the Dapr type for a given interface
     *
     * @return object
     * @throws \ReflectionException
     */
    public static function get(string $interface, mixed $id, string|null $override_type = null): object
    {
        global $dapr_container;
        Runtime::$logger?->debug('Getting actor proxy for {i}||{id}', ['i' => $interface, 'id' => $id]);

        $reflected_interface = new ReflectionClass($interface);
        $type                = $override_type ?? ($reflected_interface->getAttributes(
                    DaprType::class
                )[0] ?? null)?->newInstance()->type;

        if (empty($type)) {
            Runtime::$logger?->critical('{i} is missing a DaprType attribute', ['i' => $interface]);
            throw new LogicException("$interface must have a DaprType attribute");
        }

        $generator = ProxyModes::get_generator(self::$mode, $interface, $type);
        return $generator->get_proxy($id);
    }
}
