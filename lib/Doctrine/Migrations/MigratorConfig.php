<?php

declare(strict_types=1);

namespace Doctrine\Migrations;

class MigratorConfig
{
    /** @var bool */
    private $dryRun = false;

    /** @var bool */
    private $timeAllQueries = false;

    /** @var bool */
    private $noMigrationException = false;

    /** @var bool */
    private $allOrNothing = false;

    public function isDryRun() : bool
    {
        return $this->dryRun;
    }

    public function setDryRun(bool $dryRun) : self
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    public function getTimeAllQueries() : bool
    {
        return $this->timeAllQueries;
    }

    public function setTimeAllQueries(bool $timeAllQueries) : self
    {
        $this->timeAllQueries = $timeAllQueries;

        return $this;
    }

    public function getNoMigrationException() : bool
    {
        return $this->noMigrationException;
    }

    public function setNoMigrationException(bool $noMigrationException = false) : self
    {
        $this->noMigrationException = $noMigrationException;

        return $this;
    }

    public function isAllOrNothing() : bool
    {
        return $this->allOrNothing;
    }

    public function setAllOrNothing(bool $allOrNothing) : self
    {
        $this->allOrNothing = $allOrNothing;

        return $this;
    }
}
