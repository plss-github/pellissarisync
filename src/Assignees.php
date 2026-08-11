<?php

namespace GlpiPlugin\Pellissarisync;

use Group;
use User;

/**
 * The two assignee pickers, shared by the global configuration screen and the
 * per-agent form.
 *
 * Rendered in PHP with `display => false` rather than through the Twig field
 * macros: the macros do not forward `multiple`, the `own_ticket` right filter or
 * the `is_assign` condition, and the same markup has to come out on GLPI 10 and 11.
 * These are the very options that make the field behave like the real "Assigned to"
 * field of a ticket, which is what was asked for.
 */
final class Assignees
{
    /**
     * @param list<int> $values
     */
    public static function usersDropdown(string $name, array $values): string
    {
        return (string) User::dropdown([
            // `[]` because the field is multi-valued: PHP must receive an array.
            'name'                => $name . '[]',
            'value'               => $values,
            'values'              => $values,
            'multiple'            => true,
            // Same filter core uses for the ticket's "Assigned to": only users who
            // can actually own a ticket are offered.
            'right'               => 'own_ticket',
            'entity'              => 0,
            'entity_sons'         => true,
            'display'             => false,
            'display_emptychoice' => false,
            'width'               => '100%',
        ]);
    }

    /**
     * @param list<int> $values
     */
    public static function groupsDropdown(string $name, array $values): string
    {
        return (string) Group::dropdown([
            'name'                => $name . '[]',
            'value'               => $values,
            'values'              => $values,
            'multiple'            => true,
            // Only groups flagged as assignable, exactly as the ticket form does.
            'condition'           => ['is_assign' => 1],
            'entity'              => 0,
            'entity_sons'         => true,
            'display'             => false,
            'display_emptychoice' => false,
            'width'               => '100%',
        ]);
    }
}
