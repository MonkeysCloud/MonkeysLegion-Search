<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Enum;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Sort direction for search result ordering.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
enum SortDirection: string
{
    case Asc  = 'asc';
    case Desc = 'desc';
}
