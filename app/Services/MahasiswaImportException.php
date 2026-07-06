<?php

namespace App\Services;

use RuntimeException;

/**
 * File-level import failure (structure, headers, size) with a
 * human-readable Indonesian message safe to return to the SuperAdmin UI.
 */
class MahasiswaImportException extends RuntimeException
{
}
