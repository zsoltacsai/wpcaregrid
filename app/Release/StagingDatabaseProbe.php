<?php

namespace App\Release;

/** Reads the identity of the database the application is actually connected to. */
interface StagingDatabaseProbe
{
    /** The schema of the live connection, or null when it cannot be read. */
    public function currentDatabase(): ?string;

    /** SHA-256 of the MySQL server_uuid, or null when it cannot be read. */
    public function serverUuidSha256(): ?string;
}
