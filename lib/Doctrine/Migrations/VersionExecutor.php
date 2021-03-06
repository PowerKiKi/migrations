<?php

declare(strict_types=1);

namespace Doctrine\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Exception\SkipMigration;
use Doctrine\Migrations\Provider\SchemaDiffProviderInterface;
use Doctrine\Migrations\Tools\BytesFormatter;
use Throwable;
use function count;
use function rtrim;
use function sprintf;
use function ucfirst;

/**
 * @internal
 */
final class VersionExecutor implements VersionExecutorInterface
{
    /** @var Configuration */
    private $configuration;

    /** @var Connection */
    private $connection;

    /** @var SchemaDiffProviderInterface */
    private $schemaProvider;

    /** @var OutputWriter */
    private $outputWriter;

    /** @var ParameterFormatterInterface */
    private $parameterFormatter;

    /** @var Stopwatch */
    private $stopwatch;

    /** @var string[] */
    private $sql = [];

    /** @var mixed[] */
    private $params = [];

    /** @var mixed[] */
    private $types = [];

    public function __construct(
        Configuration $configuration,
        Connection $connection,
        SchemaDiffProviderInterface $schemaProvider,
        OutputWriter $outputWriter,
        ParameterFormatterInterface $parameterFormatter,
        Stopwatch $stopwatch
    ) {
        $this->configuration      = $configuration;
        $this->connection         = $connection;
        $this->schemaProvider     = $schemaProvider;
        $this->outputWriter       = $outputWriter;
        $this->parameterFormatter = $parameterFormatter;
        $this->stopwatch          = $stopwatch;
    }

    /**
     * @return string[]
     */
    public function getSql() : array
    {
        return $this->sql;
    }

    /**
     * @return mixed[]
     */
    public function getParams() : array
    {
        return $this->params;
    }

    /**
     * @return mixed[]
     */
    public function getTypes() : array
    {
        return $this->types;
    }

    /**
     * @param mixed[] $params
     * @param mixed[] $types
     */
    public function addSql(string $sql, array $params = [], array $types = []) : void
    {
        $this->sql[] = $sql;

        if (count($params) === 0) {
            return;
        }

        $this->addQueryParams($params, $types);
    }

    public function execute(
        Version $version,
        AbstractMigration $migration,
        string $direction,
        ?MigratorConfig $migratorConfig = null
    ) : VersionExecutionResult {
        $migratorConfig = $migratorConfig ?? new MigratorConfig();

        $versionExecutionResult = new VersionExecutionResult();

        $this->startMigration($version, $migration, $direction, $migratorConfig);

        try {
            $this->executeMigration(
                $version,
                $migration,
                $versionExecutionResult,
                $direction,
                $migratorConfig
            );

            $versionExecutionResult->setSql($this->sql);
            $versionExecutionResult->setParams($this->params);
            $versionExecutionResult->setTypes($this->types);
        } catch (SkipMigration $e) {
            $this->skipMigration(
                $e,
                $version,
                $migration,
                $direction,
                $migratorConfig
            );

            $versionExecutionResult->setSkipped(true);
        } catch (Throwable $e) {
            $this->migrationError($e, $version, $migration);

            $versionExecutionResult->setError(true);
            $versionExecutionResult->setException($e);

            throw $e;
        }

        return $versionExecutionResult;
    }

    private function startMigration(
        Version $version,
        AbstractMigration $migration,
        string $direction,
        MigratorConfig $migratorConfig
    ) : void {
        $this->sql    = [];
        $this->params = [];
        $this->types  = [];

        $this->configuration->dispatchVersionEvent(
            $version,
            Events::onMigrationsVersionExecuting,
            $direction,
            $migratorConfig->isDryRun()
        );

        if (! $migration->isTransactional()) {
            return;
        }

        // only start transaction if in transactional mode
        $this->connection->beginTransaction();
    }

    private function executeMigration(
        Version $version,
        AbstractMigration $migration,
        VersionExecutionResult $versionExecutionResult,
        string $direction,
        MigratorConfig $migratorConfig
    ) : VersionExecutionResult {
        $stopwatchEvent = $this->stopwatch->start('execute');

        $version->setState(VersionState::PRE);

        $fromSchema = $this->schemaProvider->createFromSchema();

        $migration->{'pre' . ucfirst($direction)}($fromSchema);

        if ($direction === VersionDirection::UP) {
            $this->outputWriter->write("\n" . sprintf('  <info>++</info> migrating <comment>%s</comment>', $version) . "\n");
        } else {
            $this->outputWriter->write("\n" . sprintf('  <info>--</info> reverting <comment>%s</comment>', $version) . "\n");
        }

        $version->setState(VersionState::EXEC);

        $toSchema = $this->schemaProvider->createToSchema($fromSchema);

        $migration->$direction($toSchema);

        foreach ($this->schemaProvider->getSqlDiffToMigrate($fromSchema, $toSchema) as $sql) {
            $this->addSql($sql);
        }

        if (count($this->sql) !== 0) {
            if (! $migratorConfig->isDryRun()) {
                $this->executeVersionExecutionResult($version, $migratorConfig);
            } else {
                foreach ($this->sql as $idx => $query) {
                    $this->outputSqlQuery($idx, $query);
                }
            }
        } else {
            $this->outputWriter->write(sprintf(
                '<error>Migration %s was executed but did not result in any SQL statements.</error>',
                $version
            ));
        }

        $version->setState(VersionState::POST);

        $migration->{'post' . ucfirst($direction)}($toSchema);

        if (! $migratorConfig->isDryRun()) {
            $version->markVersion($direction);
        }

        $stopwatchEvent->stop();

        $versionExecutionResult->setTime($stopwatchEvent->getDuration());
        $versionExecutionResult->setMemory($stopwatchEvent->getMemory());

        if ($direction === VersionDirection::UP) {
            $this->outputWriter->write(sprintf(
                "\n  <info>++</info> migrated (took %sms, used %s memory)",
                $stopwatchEvent->getDuration(),
                BytesFormatter::formatBytes($stopwatchEvent->getMemory())
            ));
        } else {
            $this->outputWriter->write(sprintf(
                "\n  <info>--</info> reverted (took %sms, used %s memory)",
                $stopwatchEvent->getDuration(),
                BytesFormatter::formatBytes($stopwatchEvent->getMemory())
            ));
        }

        if ($migration->isTransactional()) {
            //commit only if running in transactional mode
            $this->connection->commit();
        }

        $version->setState(VersionState::NONE);

        $this->configuration->dispatchVersionEvent(
            $version,
            Events::onMigrationsVersionExecuted,
            $direction,
            $migratorConfig->isDryRun()
        );

        return $versionExecutionResult;
    }

    private function skipMigration(
        SkipMigration $e,
        Version $version,
        AbstractMigration $migration,
        string $direction,
        MigratorConfig $migratorConfig
    ) : void {
        if ($migration->isTransactional()) {
            //only rollback transaction if in transactional mode
            $this->connection->rollBack();
        }

        if (! $migratorConfig->isDryRun()) {
            $version->markVersion($direction);
        }

        $this->outputWriter->write(sprintf("\n  <info>SS</info> skipped (Reason: %s)", $e->getMessage()));

        $version->setState(VersionState::NONE);

        $this->configuration->dispatchVersionEvent(
            $version,
            Events::onMigrationsVersionSkipped,
            $direction,
            $migratorConfig->isDryRun()
        );
    }

    /**
     * @throws Throwable
     */
    private function migrationError(Throwable $e, Version $version, AbstractMigration $migration) : void
    {
        $this->outputWriter->write(sprintf(
            '<error>Migration %s failed during %s. Error %s</error>',
            $version,
            $version->getExecutionState(),
            $e->getMessage()
        ));

        if ($migration->isTransactional()) {
            //only rollback transaction if in transactional mode
            $this->connection->rollBack();
        }

        $version->setState(VersionState::NONE);
    }

    private function executeVersionExecutionResult(
        Version $version,
        MigratorConfig $migratorConfig
    ) : void {
        foreach ($this->sql as $key => $query) {
            $stopwatchEvent = $this->stopwatch->start('query');

            $this->outputSqlQuery($key, $query);

            if (! isset($this->params[$key])) {
                $this->connection->executeQuery($query);
            } else {
                $this->connection->executeQuery($query, $this->params[$key], $this->types[$key]);
            }

            $stopwatchEvent->stop();

            if (! $migratorConfig->getTimeAllQueries()) {
                continue;
            }

            $this->outputWriter->write(sprintf('  <info>%sms</info>', $stopwatchEvent->getDuration()));
        }
    }

    /**
     * @param mixed[]|int $params
     * @param mixed[]|int $types
     */
    private function addQueryParams($params, $types) : void
    {
        $index                = count($this->sql) - 1;
        $this->params[$index] = $params;
        $this->types[$index]  = $types;
    }

    private function outputSqlQuery(int $idx, string $query) : void
    {
        $params = $this->parameterFormatter->formatParameters(
            $this->params[$idx] ?? [],
            $this->types[$idx] ?? []
        );

        $this->outputWriter->write(rtrim(sprintf(
            '     <comment>-></comment> %s %s',
            $query,
            $params
        )));
    }
}
