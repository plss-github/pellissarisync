<?php

namespace GlpiPlugin\Pellissarisync;

use RuntimeException;

/**
 * The peer is addressing a ticket that was purged here.
 *
 * Not an error: purging is a legitimate local decision that the mirror is not
 * allowed to propagate, and the peer had no way of knowing. Answering 422 -- what
 * every other failure to apply gets -- would make the sender retry eight times and
 * then bury the event as dead, over and over for every change made to a ticket
 * whose counterpart no longer exists. So this one is caught in ApiServer and
 * answered as an accepted no-op, which drains the peer's queue and says why.
 */
final class MirrorPurgedException extends RuntimeException
{
}
