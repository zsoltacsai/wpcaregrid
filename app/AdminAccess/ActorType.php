<?php

namespace App\AdminAccess;

/**
 * Who asked for access. Operators exist as a type only for now: the rules
 * for them are enforced in AdminAccessService, the UI comes with the
 * operator area.
 */
enum ActorType: string
{
    case Customer = 'customer';
    case Operator = 'operator';
}
