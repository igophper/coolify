<?php

namespace App\Actions\Database;

use App\Models\StandaloneMariadb;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Lorisleiva\Actions\Concerns\AsAction;

class StartMariadb
{
    use AsAction;

    public StandaloneMariadb $database;
    public array $commands = [];
    public string $configuration_dir;

    public function handle(StandaloneMariadb $database)
    {
        $this->database = $database;

        $container_name = $this->database->uuid;
        $this->configuration_dir = database_configuration_dir() . '/' . $container_name;

        $this->commands = [
            "echo '####### Starting {$database->name}.'",
            "mkdir -p $this->configuration_dir",
        ];

        $persistent_storages = $this->generate_local_persistent_volumes();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $environment_variables = $this->generate_environment_variables();
        $this->add_custom_mysql();
        $docker_compose = [
            'version' => '3.8',
            'services' => [
                $container_name => [
                    'image' => $this->database->image,
                    'container_name' => $container_name,
                    'environment' => $environment_variables,
                    'restart' => RESTART_MODE,
                    'networks' => [
                        $this->database->destination->network,
                    ],
                    'labels' => [
                        'coolify.managed' => 'true',
                    ],
                    'healthcheck' => [
                        'test' => ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"],
                        'interval' => '5s',
                        'timeout' => '5s',
                        'retries' => 10,
                        'start_period' => '5s'
                    ],
                    'mem_limit' => $this->database->limits_memory,
                    'memswap_limit' => $this->database->limits_memory_swap,
                    'mem_swappiness' => $this->database->limits_memory_swappiness,
                    'mem_reservation' => $this->database->limits_memory_reservation,
                    'cpus' => (int) $this->database->limits_cpus,
                    'cpuset' => $this->database->limits_cpuset,
                    'cpu_shares' => $this->database->limits_cpu_shares,
                ]
            ],
            'networks' => [
                $this->database->destination->network => [
                    'external' => true,
                    'name' => $this->database->destination->network,
                    'attachable' => true,
                ]
            ]
        ];
        if (count($this->database->ports_mappings_array) > 0) {
            $docker_compose['services'][$container_name]['ports'] = $this->database->ports_mappings_array;
        }
        if (count($persistent_storages) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = $persistent_storages;
        }
        if (count($volume_names) > 0) {
            $docker_compose['volumes'] = $volume_names;
        }
        if (!is_null($this->database->mariadb_conf)) {
            $docker_compose['services'][$container_name]['volumes'][] = [
                'type' => 'bind',
                'source' => $this->configuration_dir . '/custom-config.cnf',
                'target' => '/etc/mysql/conf.d/custom-config.cnf',
                'read_only' => true,
            ];
        }
        $docker_compose = Yaml::dump($docker_compose, 10);
        $docker_compose_base64 = base64_encode($docker_compose);
        $this->commands[] = "echo '{$docker_compose_base64}' | base64 -d > $this->configuration_dir/docker-compose.yml";
        $readme = generate_readme_file($this->database->name, now());
        $this->commands[] = "echo '{$readme}' > $this->configuration_dir/README.md";
        $this->commands[] = "echo 'Pulling {$database->image} image.'";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml pull";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml up -d";
        $this->commands[] = "echo '####### {$database->name} started.'";
        return remote_process($this->commands, $database->destination->server);
    }

    private function generate_local_persistent_volumes()
    {
        $local_persistent_volumes = [];
        foreach ($this->database->persistentStorages as $persistentStorage) {
            $volume_name = $persistentStorage->host_path ?? $persistentStorage->name;
            $local_persistent_volumes[] = $volume_name . ':' . $persistentStorage->mount_path;
        }
        return $local_persistent_volumes;
    }

    private function generate_local_persistent_volumes_only_volume_names()
    {
        $local_persistent_volumes_names = [];
        foreach ($this->database->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path) {
                continue;
            }
            $name = $persistentStorage->name;
            $local_persistent_volumes_names[$name] = [
                'name' => $name,
                'external' => false,
            ];
        }
        return $local_persistent_volumes_names;
    }

    private function generate_environment_variables()
    {
        $environment_variables = collect();
        foreach ($this->database->runtime_environment_variables as $env) {
            $environment_variables->push("$env->key=$env->value");
        }

        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('MARIADB_ROOT_PASSWORD'))->isEmpty()) {
            $environment_variables->push("MARIADB_ROOT_PASSWORD={$this->database->mariadb_root_password}");
        }

        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('MARIADB_DATABASE'))->isEmpty()) {
            $environment_variables->push("MARIADB_DATABASE={$this->database->mariadb_database}");
        }

        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('MARIADB_USER'))->isEmpty()) {
            $environment_variables->push("MARIADB_USER={$this->database->mariadb_user}");
        }
        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('MARIADB_PASSWORD'))->isEmpty()) {
            $environment_variables->push("MARIADB_PASSWORD={$this->database->mariadb_password}");
        }
        return $environment_variables->all();
    }
    private function add_custom_mysql()
    {
        if (is_null($this->database->mariadb_conf)) {
            return;
        }
        $filename = 'custom-config.cnf';
        $content = $this->database->mariadb_conf;
        $content_base64 = base64_encode($content);
        $this->commands[] = "echo '{$content_base64}' | base64 -d > $this->configuration_dir/{$filename}";
    }
}