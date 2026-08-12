<?php

declare(strict_types=1);

namespace Cbox\Engine;

use Cbox\Engine\Addons\AddonSet;
use Cbox\Engine\Console\AddonsCommand;
use Cbox\Engine\Console\ArtisanCommand;
use Cbox\Engine\Console\ComposerCommand;
use Cbox\Engine\Console\DeployCommand;
use Cbox\Engine\Console\DestroyCommand;
use Cbox\Engine\Console\DoctorCommand;
use Cbox\Engine\Console\DownCommand;
use Cbox\Engine\Console\ExposeCommand;
use Cbox\Engine\Console\LogsCommand;
use Cbox\Engine\Console\NpmCommand;
use Cbox\Engine\Console\PruneCommand;
use Cbox\Engine\Console\PushCommand;
use Cbox\Engine\Console\RemoveCommand;
use Cbox\Engine\Console\RunCommand;
use Cbox\Engine\Console\SandboxCommand;
use Cbox\Engine\Console\SetupCommand;
use Cbox\Engine\Console\SleepCommand;
use Cbox\Engine\Console\StatusCommand;
use Cbox\Engine\Console\TrustCommand;
use Cbox\Engine\Console\UnexposeCommand;
use Cbox\Engine\Console\UninstallCommand;
use Cbox\Engine\Console\UpCommand;
use Cbox\Engine\Console\WakeCommand;
use Cbox\Engine\Contracts\ClusterManager;
use Cbox\Engine\Contracts\CommandRunner;
use Cbox\Engine\Contracts\ContainerRuntime;
use Cbox\Engine\Contracts\HostResolver;
use Cbox\Engine\Contracts\HttpProbe;
use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Docker\DockerRuntime;
use Cbox\Engine\Docker\LocalHttpProbe;
use Cbox\Engine\Docker\ProcessCommandRunner;
use Cbox\Engine\Host\MacResolver;
use Cbox\Engine\Kind\ClusterConfig;
use Cbox\Engine\Kind\HostPorts;
use Cbox\Engine\Kind\KindCluster;
use Cbox\Engine\Kind\PublishedPorts;
use Cbox\Engine\Kubernetes\NodeKubectl;
use Cbox\Engine\Platform\ClusterObjects;
use Cbox\Engine\Platform\LocalAuthority;
use Cbox\Engine\Platform\LocalTarget;
use Cbox\Engine\Platform\ProjectListeners;
use Cbox\Engine\Project\ConnectionSourceFactory;
use Cbox\Engine\Project\DatabasePasswords;
use Cbox\Engine\Project\GithubToken;
use Cbox\Engine\Project\ImageBuilder;
use Cbox\Engine\Project\ImageDigest;
use Cbox\Engine\Project\ProjectDeployer;
use Cbox\Engine\Project\ProjectManifestReader;
use Cbox\Engine\Project\ProjectRegistry;
use Cbox\Engine\Project\SidecarCompiler;
use Cbox\Engine\Support\Home;
use Cbox\Engine\Tunnel\CloudflareTunnel;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

/**
 * The engine's bindings.
 *
 * Contracts, never concretes, so the CLI and the desktop application resolve the
 * same objects and a test can put a fake in front of either. There is exactly one
 * place in this application that touches the host — `CommandRunner` — and it is
 * bound here.
 */
class EngineServiceProvider extends ServiceProvider
{
    /**
     * Everything the consumer gets: the commands, and the versions they pin.
     *
     * REGISTERED EXPLICITLY, because a package has no auto-discovery. When this
     * code lived in an application, `app/Console/Commands` was scanned and a new
     * file simply appeared. Here a command nobody lists is a command nobody can
     * run, so the list is the contract — and the test that counts it is the
     * thing that notices when one is added and forgotten.
     */
    public function boot(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/cbox.php', 'cbox');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands(self::COMMANDS);

        $this->publishes([
            dirname(__DIR__).'/config/cbox.php' => $this->app->configPath('cbox.php'),
        ], 'cbox-config');
    }

    /** @var list<class-string<Command>> */
    public const COMMANDS = [
        AddonsCommand::class,
        ArtisanCommand::class,
        ComposerCommand::class,
        DeployCommand::class,
        DestroyCommand::class,
        DoctorCommand::class,
        DownCommand::class,
        ExposeCommand::class,
        LogsCommand::class,
        NpmCommand::class,
        PruneCommand::class,
        PushCommand::class,
        RemoveCommand::class,
        RunCommand::class,
        SandboxCommand::class,
        SetupCommand::class,
        SleepCommand::class,
        StatusCommand::class,
        TrustCommand::class,
        UnexposeCommand::class,
        UninstallCommand::class,
        UpCommand::class,
        WakeCommand::class,
    ];

    public function register(): void
    {
        $this->app->bind(CommandRunner::class, ProcessCommandRunner::class);

        // Explicit, because the container will not fill a NULLABLE dependency
        // that has a default — it hands over the default. Left to autowiring
        // the reader gets no token resolver, and a real build refuses with
        // the message that says how to fix a thing that is already fixed.
        $this->app->bind(
            ProjectManifestReader::class,
            fn (Container $app): ProjectManifestReader => new ProjectManifestReader(
                $app->make(GithubToken::class),
            ),
        );
        $this->app->bind(ContainerRuntime::class, DockerRuntime::class);
        $this->app->bind(HttpProbe::class, LocalHttpProbe::class);
        // The ports are READ OFF THE RUNNING CLUSTER, so a resolver file and a
        // printed address always agree with what is actually published — kind
        // fixes its mappings when the cluster is built, and a machine can hold
        // one created back when something else had 443.
        $this->app->singleton(
            HostPorts::class,
            fn (Container $app): HostPorts => $app->make(PublishedPorts::class)->current(),
        );

        $this->app->bind(
            HostResolver::class,
            fn (Container $app): MacResolver => new MacResolver($app->make(HostPorts::class)),
        );

        // The authority lives beside the developer's own configuration rather
        // than in this application's storage: it has to survive the tool being
        // reinstalled, and a root somebody has trusted is theirs rather than an
        // artefact of an install.
        $this->app->singleton(LocalAuthority::class, fn (Container $app): LocalAuthority => new LocalAuthority(
            $app->make(Kubernetes::class),
            Home::directory().'/.cbox/ca',
        ));

        $this->app->singleton(
            ClusterObjects::class,
            fn (Container $app): ClusterObjects => new ClusterObjects($app->make(LocalAuthority::class)),
        );

        // The deployer needs to know what else is deployed, to keep the shared
        // gateway's listeners derived rather than accumulated.
        // The connector's image is pinned in config, and read once here rather
        // than by whatever needs a tunnel.
        $this->app->singleton(
            CloudflareTunnel::class,
            fn (): CloudflareTunnel => new CloudflareTunnel(
                is_string(config('cbox.tunnel.image')) ? config('cbox.tunnel.image') : '',
            ),
        );

        $this->app->bind(ProjectDeployer::class, fn (Container $app): ProjectDeployer => new ProjectDeployer(
            // NAMED, because this list has grown and a positional argument
            // inserted in the middle once silently handed a SidecarCompiler to
            // a parameter expecting something else. The type system caught it;
            // it should not have had the chance.
            kubernetes: $app->make(Kubernetes::class),
            target: $app->make(LocalTarget::class),
            connections: new ConnectionSourceFactory,
            passwords: new DatabasePasswords($app->make(Kubernetes::class)),
            listeners: new ProjectListeners,
            registry: $app->make(ProjectRegistry::class),
            images: new ImageBuilder($app->make(CommandRunner::class)),
            sidecars: new SidecarCompiler,
            ports: $app->make(HostPorts::class),
            digests: new ImageDigest($app->make(CommandRunner::class)),
        ));

        // The config is written where the application can write, and read by a
        // program outside it — so it is a real path rather than a temporary
        // file, and a developer who wants to see what their cluster is can.
        $this->app->singleton(
            ClusterConfig::class,
            fn (Container $app): ClusterConfig => new ClusterConfig(
                // ~/.cbox, NOT the consuming application's storage directory.
                // The CLI and the desktop drive ONE cluster on this machine, and
                // a path relative to whichever of them happens to be running
                // would give them two — the same reason the certificate
                // authority lives there. See LocalAuthority.
                Home::directory().'/.cbox/kind.yaml',
                $app->make(HostPorts::class),
            ),
        );

        $this->app->bind(ClusterManager::class, KindCluster::class);
        $this->app->bind(Kubernetes::class, NodeKubectl::class);

        $this->app->singleton(
            AddonSet::class,
            // Shipped WITH the engine rather than by whoever consumes it. The
            // rendered manifests are what makes a local cluster match a cell,
            // and a copy per consumer is a copy that drifts.
            fn (): AddonSet => new AddonSet(dirname(__DIR__).'/resources/addons'),
        );

    }
}
