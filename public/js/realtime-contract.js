/**
 * The realtime private-channel contract — one description, both clients.
 *
 * Three unrelated payloads ride `chat:private_messages` under the single event
 * name `private`: a real direct message, a typing cue (TypingController and
 * /api/admin/impersonate-typing) and a reaction update
 * (ReactionService::publishDmUpdate). Telling them apart is not optional — a
 * consumer that assumes "private event = message" draws a typing cue as an empty
 * bubble, and draws a reaction under the id of the message it reacted to, which
 * then hides the real message behind it.
 *
 * That logic used to live twice, written from scratch in each client: the chat
 * filtered these payloads, the admin panel did not, and the admin grew exactly
 * those two bugs. Anything new that starts sharing the channel must be taught
 * here, once, and both clients follow — so keep this file the only place that
 * knows the shape of a private-channel payload.
 *
 * Loaded as a plain script before each client (no modules, matching the rest of
 * the codebase) and exposed as window.RcbRealtime.
 *
 * Server-side counterparts:
 *   src/Controllers/TypingController.php            (type: 'typing')
 *   src/Controllers/AdminImpersonationController.php (type: 'typing')
 *   src/Services/ReactionService.php                 (type: 'reaction')
 *   src/Controllers/MessageActionController.php      (a real DM)
 */
(function (global) {
    'use strict';

    var Rcb = global.RcbRealtime || (global.RcbRealtime = {});

    /** A peer started/stopped typing. Never rendered as a message. */
    Rcb.PRIVATE_TYPING = 'typing';
    /** The peer read what I sent them (receipt, not content). */
    Rcb.PRIVATE_READ = 'read';
    /** Reaction counts changed on a message that already exists. */
    Rcb.PRIVATE_REACTION = 'reaction';
    /** "Someone reacted to your message" — addressed to the author, not the thread. */
    Rcb.PRIVATE_REACTION_NOTIFICATION = 'reaction_notification';
    /** A real direct message, safe to render. */
    Rcb.PRIVATE_MESSAGE = 'message';
    /** Anything we do not recognise — callers must ignore it, not guess. */
    Rcb.PRIVATE_UNKNOWN = 'unknown';

    /** Every payload that is tagged with an explicit `type`. */
    Rcb.TAGGED_PRIVATE_KINDS = [
        Rcb.PRIVATE_TYPING,
        Rcb.PRIVATE_READ,
        Rcb.PRIVATE_REACTION,
        Rcb.PRIVATE_REACTION_NOTIFICATION
    ];

    /**
     * What kind of payload is this?
     *
     * Recognising a message is deliberately positive rather than "not one of the
     * tagged kinds": an untagged payload we have never seen is UNKNOWN and gets
     * dropped, instead of reaching the renderer as a bubble with no text and no
     * timestamp. A real DM always carries an id and a body.
     *
     * @param {*} data payload delivered under the 'private' event
     * @returns {string} one of the PRIVATE_* constants
     */
    Rcb.classifyPrivatePayload = function (data) {
        if (!data || typeof data !== 'object') {
            return Rcb.PRIVATE_UNKNOWN;
        }
        if (Rcb.TAGGED_PRIVATE_KINDS.indexOf(data.type) !== -1) {
            return data.type;
        }

        var hasId = data.id !== undefined && data.id !== null
            || data.message_id !== undefined && data.message_id !== null;
        var hasBody = !!data.message
            || !!(data.attachment && data.attachment.file_path);

        return (hasId && hasBody) ? Rcb.PRIVATE_MESSAGE : Rcb.PRIVATE_UNKNOWN;
    };

    /**
     * The id a DM is keyed by. Live broadcasts carry `id`, some paths carry
     * `message_id`; reaction updates always use `message_id`. Returned as-is
     * (number or string) — callers stringify when matching a DOM attribute.
     */
    Rcb.privateMessageId = function (data) {
        if (!data || typeof data !== 'object') {
            return null;
        }
        if (data.id !== undefined && data.id !== null) {
            return data.id;
        }
        return data.message_id !== undefined && data.message_id !== null
            ? data.message_id
            : null;
    };

    /**
     * Reaction broadcasts carry a {emoji: count} map, while rendered history
     * carries a [{emoji, count, mine}] list. Normalise to the list shape both
     * clients draw from, dropping anything that fell to zero.
     */
    Rcb.reactionCountsToList = function (counts) {
        if (!counts || typeof counts !== 'object') {
            return [];
        }
        return Object.keys(counts)
            .map(function (emoji) { return { emoji: emoji, count: counts[emoji] | 0 }; })
            .filter(function (r) { return r.count > 0; });
    };
})(typeof window !== 'undefined' ? window : this);
