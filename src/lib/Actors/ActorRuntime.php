<?php

namespace Dapr\Actors;

use Dapr\Deserializer;
use Dapr\exceptions\CommitFailed;
use Dapr\Formats;
use Dapr\Serializer;
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * The Actor Runtime
 */
class ActorRuntime
{
    public static $input = 'php://input';
    public static $actors = [];
    public static $config = [
        'entities' => [],
    ];

    #[ArrayShape([
        'type'          => 'string|null',
        'dapr_type'     => 'string',
        'id'            => 'string|int',
        'function'      => 'string',
        'method_name'   => 'string|null',
        'reminder_name' => 'string|null',
        'body'          => 'array',
    ])]
    public static function extract_parts_from_request(
        string $http_method,
        string $uri
    ): ?array {
        if ( ! str_starts_with(haystack: $uri, needle: '/actors')) {
            return null;
        }
        $parts = array_values(array_filter(explode('/', $uri)));

        return [ // add try/catches
            'type'          => self::$actors[$parts[1]] ?? null,
            'dapr_type'     => $parts[1],
            'id'            => $parts[2],
            'function'      => match ($http_method) {
                'DELETE' => 'delete',
                default => $parts[3],
            },
            'method_name'   => $parts[4] ?? null,
            'reminder_name' => $parts[5] ?? null,
            'body'          => match ($http_method) {
                'POST', 'PUT' => Deserializer::maybe_deserialize(json_decode(self::get_input(), true)),
                default => null,
            },
        ];
    }

    public static function get_input(): string
    {
        return file_get_contents(self::$input);
    }

    #[ArrayShape(['code' => 'int', 'body' => 'null|array'])]
    public static function handle_invoke(
        #[ArrayShape([
            'type'          => 'string|null',
            'dapr_type'     => 'string',
            'id'            => 'string|int',
            'function'      => 'string',
            'method_name'   => 'string|null',
            'reminder_name' => 'string|null',
            'body'          => 'array',
        ])] array $description
    ): array {
        if ($description['type'] === null || ! class_exists($description['type'])) {
            return ['code' => 404];
        }

        try {
            $reflection = new ReflectionClass($description['type']);
            $traits     = $reflection->getTraitNames();
            $has_state  = in_array('Dapr\Actors\ActorState', $traits);
            $is_actor   = $reflection->implementsInterface('Dapr\Actors\IActor')
                          && $reflection->isInstantiable() && $reflection->isUserDefined();
        } catch (\ReflectionException $ex) {
            trigger_error($ex->getMessage(), E_USER_WARNING);

            return [
                'code' => 500,
                'body' => [
                    'error' => $ex->getMessage(),
                ],
            ];
        }

        if ( ! $is_actor) {
            trigger_error('Actor does not implement IActor interface', E_USER_WARNING);

            return ['code' => 404];
        }

        $state_config = null;
        if ($has_state) {
            $state_config = self::get_state_type($description['type']);
            /**
             * @psalm-suppress UndefinedClass
             */
            $state        = InternalActorState::begin_actor(
                $description['dapr_type'],
                $description['id'],
                $state_config['type'],
                $state_config['store'],
                new $state_config['consistency']
            );

            /**
             * @var IActor
             */
            $actor = new $description['type']($description['id'], $state);
        } else {
            /**
             * @var IActor
             */
            $actor = new $description['type']($description['id']);
        }

        $activation_tracker = hash('sha256', $description['dapr_type'].$description['id']);
        $activation_tracker = rtrim(sys_get_temp_dir(), '/').'/dapr_'.$activation_tracker;

        $is_activated = file_exists($activation_tracker);

        if ( ! $is_activated) {
            touch($activation_tracker);
            $actor->on_activation();
        }

        $return = [
            'code' => 200,
        ];

        switch ($description['function']) {
            case 'method':
                switch ($description['method_name']) {
                    case 'remind':
                        $data = $description['body'];
                        $actor->remind(
                            $description['reminder_name'],
                            Deserializer::maybe_deserialize(json_decode($data['data'], true))
                        );
                        break;
                    case 'timer':
                        $data     = $description['body'];
                        $callback = $data['callback'];
                        call_user_func_array([$actor, $callback], $data['data'] ?? []);
                        break;
                    default:
                        $result         = call_user_func_array(
                            [$actor, $description['method_name']],
                            $description['body']
                        );
                        $return['body'] = json_encode(Serializer::as_json($result));
                        break;
                }
                break;
            case 'delete':
                $actor->on_deactivation();
                unlink($activation_tracker);
                break;
        }

        if ($has_state) {
            try {
                InternalActorState::commit($state, $state_config['metadata'] ?? []);
            } catch (CommitFailed $ex) {
                trigger_error($ex->getMessage(), E_USER_WARNING);

                return ['code' => 500, 'body' => ['error' => $ex->getMessage()]];
            }
        }

        return $return;
    }

    /**
     * Read a given type for a constant with the name STATE_TYPE.
     *
     * @param string $type The type to read from.
     *
     * @return mixed The state type definition.
     *
     * @psalm-return array<array-key, scalar>|null|scalar
     */
    private static function get_state_type(string $type): mixed
    {
        try {
            $reflection = new ReflectionClassConstant($type, 'STATE_TYPE');

            return $reflection->getValue();
        } catch (Exception $ex) {
            trigger_error("Class $type is using ActorState but lacking a STATE_TYPE constant.", E_USER_ERROR);
        }
    }

    /**
     * Register an actor that this app support.
     *
     * @param string $dapr_type The Dapr type
     * @param string $actor_type The actor to initialize when invoked
     */
    public static function register_actor(string $dapr_type, string $actor_type): void
    {
        self::$actors[$dapr_type]   = $actor_type;
        self::$config['entities'][] = $dapr_type;
    }

    /**
     * A duration which specifies how often to scan for actors to deactivate idle actors. Actors that have been idle
     * longer than the actorIdleTimeout will be deactivated.
     *
     * @param \DateInterval $interval The scan interval
     */
    public static function set_scan_interval(\DateInterval $interval): void
    {
        self::$config['actorScanInterval'] = Formats::normalize_interval($interval);
    }

    /**
     * Specifies how long to wait before deactivating an idle actor. An actor is idle if no actor method calls and no
     * reminders have fired on it.
     *
     * @param \DateInterval $timeout The timeout
     */
    public static function set_idle_timeout(\DateInterval $timeout): void
    {
        self::$config['actorIdleTimeout'] = Formats::normalize_interval($timeout);
    }

    /**
     * A duration used when in the process of draining rebalanced actors. This specifies how long to wait for the
     * current active actor method to finish. If there is no current actor method call, this is ignored.
     *
     * @param \DateInterval $timeout The timeout
     */
    public static function set_drain_timeout(\DateInterval $timeout): void
    {
        self::$config['drainOngoingCallTimeout'] = Formats::normalize_interval($timeout);
    }

    /**
     * A bool. If true, Dapr will wait for drainOngoingCallTimeout to allow a current actor call to complete before
     * trying to deactivate an actor. If false, do not wait.
     *
     * @param bool $drain Whether to drain active actors
     */
    public static function do_drain_actors(bool $drain)
    {
        self::$config['drainRebalancedActors'] = $drain;
    }

    public static function handle_config(): array
    {
        return [
            'code' => 200,
            'body' => json_encode(self::$config),
        ];
    }
}