<?php

namespace GlpiPlugin\Pellissarisync;

use GlpiPlugin\Pellissarisync\Protocol\Envelope;
use GlpiPlugin\Pellissarisync\Sync\ActorSync;
use GlpiPlugin\Pellissarisync\Sync\CostSync;
use GlpiPlugin\Pellissarisync\Sync\DocumentSync;
use GlpiPlugin\Pellissarisync\Sync\FollowupSync;
use GlpiPlugin\Pellissarisync\Sync\SolutionSync;
use GlpiPlugin\Pellissarisync\Sync\TaskSync;
use GlpiPlugin\Pellissarisync\Sync\TicketSync;
use GlpiPlugin\Pellissarisync\Sync\ValidationSync;
use GlpiPlugin\Pellissarisync\Compat;
use Throwable;

/**
 * Inbound side of the protocol.
 *
 * Reached through front/api.php, which boot() declares stateless: no session, no
 * cookies, no CSRF and no login check. Authentication is therefore entirely this
 * class's responsibility.
 */
final class ApiServer
{
    /**
     * @return array{status: int, body: array}
     */
    public static function handle(string $action, string $rawBody, array $headers): array
    {
        if (Config::role() === Config::ROLE_NONE) {
            return ['status' => 503, 'body' => ['error' => 'plugin is not configured']];
        }

        if (!in_array($action, Envelope::actions(), true)) {
            return ['status' => 404, 'body' => ['error' => 'unknown action']];
        }

        $uuid      = (string) ($headers[Envelope::HEADER_UUID] ?? '');
        $token     = (string) ($headers[Envelope::HEADER_TOKEN] ?? '');
        $signature = (string) ($headers[Envelope::HEADER_SIGN] ?? '');
        $idemKey   = (string) ($headers[Envelope::HEADER_IDEM] ?? '');

        // The handshake has no credentials yet: it authenticates with the
        // enrollment token instead.
        if ($action === Envelope::ACTION_HANDSHAKE) {
            return Handshake::serve($rawBody, $uuid, $signature);
        }

        $agent = Agent::findByUuid($uuid);
        if ($agent === null) {
            Log::write('rejected: unknown peer', ['uuid' => $uuid, 'action' => $action]);

            return ['status' => 401, 'body' => ['error' => 'unknown peer']];
        }

        if (($agent->fields['link_status'] ?? '') === Agent::STATUS_REVOKED
            || (int) ($agent->fields['is_active'] ?? 0) !== 1
        ) {
            return ['status' => 403, 'body' => ['error' => 'peer is revoked or inactive']];
        }

        if (!hash_equals($agent->getAuthToken(), $token)) {
            Log::write('rejected: bad token', ['uuid' => $uuid, 'action' => $action]);

            return ['status' => 401, 'body' => ['error' => 'invalid token']];
        }

        if (!Envelope::verify($rawBody, $agent->getAuthSecret(), $signature)) {
            Log::write('rejected: bad signature', ['uuid' => $uuid, 'action' => $action]);

            return ['status' => 401, 'body' => ['error' => 'invalid signature']];
        }

        $agent->markContact(200);

        if ($action === Envelope::ACTION_PING) {
            // The caller's own link state travels back, so an agent can discover
            // that the master has finally bound it to a customer entity.
            return ['status' => 200, 'body' => self::pong($agent)];
        }

        // A retried delivery must be a no-op, not a duplicate.
        $cached = Inbox::findResult($agent->getID(), $idemKey);
        if ($cached !== null) {
            return ['status' => 200, 'body' => $cached + ['replayed' => true]];
        }

        if (!$agent->isUsable()) {
            return [
                'status' => 409,
                'body'   => ['error' => 'peer is not linked to a customer entity yet'],
            ];
        }

        $payload = Envelope::decode($rawBody);

        // Core writes audit entries with $_SESSION['glpiname'] (Document and
        // Event::log among others). The stateless endpoint has no session, which
        // makes every mirrored write emit an "undefined array key" warning, so a
        // machine identity is supplied for the audit trail.
        $_SESSION['glpiname'] ??= 'pellissarisync';

        try {
            // No session exists on this path, so rights checks must be bypassed
            // explicitly -- the same primitive core uses for webhooks.
            $body = Compat::asSystem(
                static fn(): array => self::dispatch($action, $agent, $payload)
            );
        } catch (MirrorPurgedException $e) {
            // Accepted and dropped. The peer is talking about a ticket that was
            // purged here, which it could not have known: purge is never propagated.
            // Answering 422 like any other failure would make it retry eight times
            // and bury the event, again for every single change to that ticket.
            Log::write('inbound change ignored: the local ticket was purged', [
                'action' => $action,
                'uuid'   => $uuid,
            ]);

            $body = ['ok' => true, 'ignored' => 'the mirrored ticket was purged on this end'];
        } catch (Throwable $e) {
            Log::write('inbound apply failed: ' . $e->getMessage(), [
                'action' => $action,
                'uuid'   => $uuid,
                'line'   => $e->getLine(),
            ]);

            return ['status' => 422, 'body' => ['error' => $e->getMessage()]];
        }

        // An apply that reports failure must not answer 200: the sender would mark the
        // event delivered and the difference between the two ends would never be
        // noticed again. Answering 422 keeps it in the outbox, where the retry, the
        // eventual dead row and the log all make it visible.
        if (array_key_exists('ok', $body) && $body['ok'] === false) {
            Log::write('inbound apply reported failure', ['action' => $action, 'uuid' => $uuid]);

            return ['status' => 422, 'body' => ['error' => 'the peer could not apply this change']];
        }

        Inbox::record($agent->getID(), $idemKey, $action, $body);

        return ['status' => 200, 'body' => $body];
    }

    private static function dispatch(string $action, Agent $agent, array $payload): array
    {
        $ticketPayload   = (array) ($payload['ticket'] ?? []);
        $followupPayload = (array) ($payload['followup'] ?? []);
        $documentPayload = (array) ($payload['document'] ?? []);
        $itemPayload     = (array) ($payload['item'] ?? []);

        return match ($action) {
            Envelope::ACTION_TICKET_CREATE  => TicketSync::create($agent, $ticketPayload) + ['ok' => true],
            Envelope::ACTION_TICKET_CONTENT => self::ticketContent($agent, $ticketPayload),
            Envelope::ACTION_TICKET_STATUS  => self::ticketStatus($agent, $ticketPayload),
            Envelope::ACTION_TICKET_ACTORS  => self::ticketActors($agent, $ticketPayload),
            Envelope::ACTION_TICKET_DELETE  => ['ok' => TicketSync::delete(self::requireMirror($agent, $ticketPayload))],
            Envelope::ACTION_TICKET_RESTORE => ['ok' => TicketSync::restore(self::requireMirror($agent, $ticketPayload))],
            Envelope::ACTION_FUP_CREATE     => self::followupCreate($agent, $ticketPayload, $followupPayload),
            Envelope::ACTION_FUP_UPDATE     => self::followupUpdate($agent, $ticketPayload, $followupPayload),
            Envelope::ACTION_DOC_CREATE     => self::documentCreate($agent, $ticketPayload, $documentPayload),
            Envelope::ACTION_SOL_CREATE     => SolutionSync::create(self::requireMirror($agent, $ticketPayload), $itemPayload) + ['ok' => true],
            Envelope::ACTION_TASK_CREATE    => TaskSync::create(self::requireMirror($agent, $ticketPayload), $itemPayload) + ['ok' => true],
            Envelope::ACTION_TASK_UPDATE    => ['ok' => TaskSync::update(self::requireItem($agent, $ticketPayload, $itemPayload, TaskSync::ITEMTYPE), $itemPayload)],
            Envelope::ACTION_COST_CREATE    => CostSync::create(self::requireMirror($agent, $ticketPayload), $itemPayload) + ['ok' => true],
            Envelope::ACTION_COST_UPDATE    => ['ok' => CostSync::update(self::requireItem($agent, $ticketPayload, $itemPayload, CostSync::ITEMTYPE), $itemPayload)],
            Envelope::ACTION_VAL_CREATE     => ValidationSync::create(self::requireMirror($agent, $ticketPayload), $itemPayload) + ['ok' => true],
            Envelope::ACTION_VAL_UPDATE     => self::validationUpdate($agent, $ticketPayload, $itemPayload),
            default                         => ['ok' => false],
        };
    }

    private static function ticketActors(Agent $agent, array $ticketPayload): array
    {
        $mirror = self::requireMirror($agent, $ticketPayload);

        return [
            'ok'    => true,
            'added' => ActorSync::apply($mirror, (array) ($ticketPayload['actors'] ?? [])),
        ];
    }

    /**
     * An approval answer. Unlike a task or a cost, a missing link is not an error:
     * the approval may have landed here as a followup because no local user owns the
     * approver's address, and then the answer lands the same way.
     */
    private static function validationUpdate(Agent $agent, array $ticketPayload, array $itemPayload): array
    {
        $mirror = self::requireMirror($agent, $ticketPayload);

        $link = MirrorItem::forPeerItem(
            $mirror->getID(),
            ValidationSync::ITEMTYPE,
            (int) ($itemPayload['remote_id'] ?? 0)
        );

        return ['ok' => ValidationSync::update($mirror, $link, $itemPayload)];
    }

    /**
     * The local counterpart of a task, cost or approval the peer is editing.
     *
     * Resolved without filtering on `origin`: an approval answer travels from the end
     * that answered, so the row may have been created by either side.
     */
    private static function requireItem(Agent $agent, array $ticketPayload, array $itemPayload, string $itemtype): MirrorItem
    {
        $mirror = self::requireMirror($agent, $ticketPayload);

        $link = MirrorItem::forPeerItem(
            $mirror->getID(),
            $itemtype,
            (int) ($itemPayload['remote_id'] ?? 0)
        );

        if ($link === null) {
            throw new \RuntimeException(sprintf('unknown mirrored %s', $itemtype));
        }

        return $link;
    }

    private static function documentCreate(Agent $agent, array $ticketPayload, array $documentPayload): array
    {
        $mirror = self::requireMirror($agent, $ticketPayload);

        return DocumentSync::create($mirror, $documentPayload) + ['ok' => true];
    }

    private static function ticketContent(Agent $agent, array $ticketPayload): array
    {
        $mirror = self::requireMirror($agent, $ticketPayload);

        // Content only ever flows from the end that owns it. A peer trying to
        // rewrite text it does not own is refused, not silently applied.
        if ($mirror->isContentOwner()) {
            throw new \RuntimeException('the local instance owns this content');
        }

        return ['ok' => TicketSync::updateContent($mirror, $ticketPayload)];
    }

    private static function ticketStatus(Agent $agent, array $ticketPayload): array
    {
        $mirror = self::requireMirror($agent, $ticketPayload);

        return ['ok' => TicketSync::updateStatus($mirror, $ticketPayload['status'] ?? null)];
    }

    private static function followupCreate(Agent $agent, array $ticketPayload, array $followupPayload): array
    {
        $mirror = self::requireMirror($agent, $ticketPayload);

        return FollowupSync::create($mirror, $followupPayload) + ['ok' => true];
    }

    private static function followupUpdate(Agent $agent, array $ticketPayload, array $followupPayload): array
    {
        $mirror = self::requireMirror($agent, $ticketPayload);

        $link = MirrorFollowup::forRemoteFollowup(
            $mirror->getID(),
            (int) ($followupPayload['remote_id'] ?? 0),
            TicketSync::remoteRole()
        );

        if ($link === null) {
            throw new \RuntimeException('unknown followup');
        }

        return ['ok' => FollowupSync::updateContent($link, $followupPayload)];
    }

    /**
     * `remote_id` in a payload is always the sender's local id, which maps to our
     * remote_tickets_id column.
     */
    private static function requireMirror(Agent $agent, array $ticketPayload): Mirror
    {
        $mirror = Mirror::forRemoteTicket($agent->getID(), (int) ($ticketPayload['remote_id'] ?? 0));

        if ($mirror === null) {
            throw new \RuntimeException('unknown mirrored ticket');
        }

        // The row is a tombstone: the ticket it pointed at was purged here. Told
        // apart from an unknown mirror on purpose -- one is a broken link worth
        // retrying and logging as a failure, the other is a local decision the peer
        // has to be allowed to move past.
        if ($mirror->isPurged()) {
            throw new MirrorPurgedException('the mirrored ticket was purged locally');
        }

        return $mirror;
    }

    public static function pong(?Agent $caller = null): array
    {
        $body = [
            'ok'             => true,
            'role'           => Config::role(),
            'uuid'           => Config::uuid(),
            'glpi_version'   => GLPI_VERSION,
            'plugin_version' => PLUGIN_PELLISSARISYNC_VERSION,
            'mirrored'       => countElementsInTable(Mirror::getTable()),
            'pending'        => Outbox::countPending(),
        ];

        if ($caller !== null) {
            $body['your_link_status'] = (string) $caller->fields['link_status'];
        }

        return $body;
    }
}
