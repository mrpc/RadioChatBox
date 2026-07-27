<?php

namespace RadioChatBox\Http;

use Pramnos\Http\Response;
use Pramnos\Validation\ValidationException;
use Pramnos\Validation\Validator;

/**
 * Thin bridge from the framework Validator to RadioChatBox's error contract.
 *
 * Controllers declare their rules once and call check(); on failure they get a
 * ready-to-return 400 response shaped like the endpoints always returned —
 * `{success:false, error:<first message>}` — so the frontend (which reads
 * `data.error` and checks `!response.ok`) is unaffected. A machine-readable
 * `errors` map ({field:[messages]}) is added alongside. On success, null.
 *
 * Pass custom messages keyed "field.rule" to preserve exact user-facing text
 * (e.g. 'age.min' => 'Age must be between 18 and 120').
 *
 * @see \Pramnos\Validation\Validator
 */
final class Validate
{
    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $messages
     * @return Response|null 400 error response on failure, null when valid.
     */
    public static function check(array $data, array $rules, array $messages = []): ?Response
    {
        try {
            Validator::validate($data, $rules, $messages);
            return null;
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $first  = 'Invalid input';
            foreach ($errors as $fieldMessages) {
                if (is_array($fieldMessages) && $fieldMessages !== []) {
                    $first = (string) $fieldMessages[0];
                    break;
                }
            }
            return Response::json(['success' => false, 'error' => $first, 'errors' => $errors], 400);
        }
    }
}
