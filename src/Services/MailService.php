<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * The single way the app sends transactional email, and the reason a failure is
 * never silent.
 *
 * The framework mailer records every attempt in `mails` — but only once it gets
 * as far as attempting one. {@see \Pramnos\Email\Email::sendWithSymfonyMailer()}
 * builds a Symfony DSN from the smtp_* settings unconditionally, so on an
 * unconfigured station it throws inside the transport factory *before*
 * recordMail() runs. Nothing reaches the log, the caller catches the exception
 * to avoid failing the user's registration, and the send disappears without a
 * trace. That is how this install ran with no SMTP configured at all while
 * password resets and verification emails appeared to succeed.
 *
 * So this wrapper owns the failure path: it refuses to send when the mailer is
 * not configured, catches anything the mailer throws, and writes the failed
 * attempt into `mails` itself — same shape the framework uses, so the admin's
 * Email Log shows attempted-and-failed alongside sent.
 */
class MailService
{
    /** Matches Pramnos\Messaging\Mail::STATUS_* and Email::recordMail(). */
    private const STATUS_SENT   = 1;
    private const STATUS_FAILED = 0;

    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? new SettingsService();
    }

    /**
     * Whether a mailer is configured well enough to attempt a send.
     *
     * There is no local-mail fallback: a blank smtp_host makes the transport
     * factory throw, so "unconfigured" and "broken" are the same state.
     */
    public function isConfigured(): bool
    {
        return trim((string) $this->settings->get('smtp_host', '')) !== '';
    }

    /**
     * Send one email. Never throws — the caller's flow (registration, password
     * reset) must not fail because the mailer is down.
     *
     * @param string $module Tag stored on the log row, e.g. 'verification'.
     * @return bool Whether the mail actually went out.
     */
    public function send(string $to, string $subject, string $body, string $module = ''): bool
    {
        if (!$this->isConfigured()) {
            $this->record($to, $subject, $body, $module, false, 'No SMTP host configured (Settings → Email).');
            return false;
        }

        try {
            $email = \Pramnos\Email\Email::getInstance();
            $email->setTo($to);
            $email->setSubject($subject);
            $email->setBody($body);

            $from = trim((string) $this->settings->get('mail_from', ''));
            if ($from !== '') {
                $email->setFrom($from);
            }

            // A truthy return means delivered; the mailer has already logged it.
            if ($email->send()) {
                return true;
            }

            // Reached the transport and was refused — Email::recordMail() has
            // already written the failed row, so do not write a second one.
            return false;
        } catch (\Throwable $e) {
            $this->record($to, $subject, $body, $module, false, $e->getMessage());
            return false;
        }
    }

    /**
     * Write an attempt into `mails` ourselves, for the failures the framework
     * never gets far enough to record. Best-effort: a logging problem must not
     * turn into a second failure on top of the one being reported.
     */
    private function record(
        string $to,
        string $subject,
        string $body,
        string $module,
        bool $success,
        string $error
    ): void {
        try {
            $date = time();
            PramnosDatabase::getInstance()->queryBuilder()
                ->table('mails')
                ->insert([
                    'status'     => $success ? self::STATUS_SENT : self::STATUS_FAILED,
                    'frommail'   => (string) $this->settings->get('mail_from', ''),
                    'fromname'   => '',
                    'tomail'     => $to,
                    'toname'     => '',
                    'subject'    => $subject,
                    'content'    => $body,
                    'date'       => $date,
                    'module'     => $module,
                    'moduleinfo' => '',
                    'extrainfo'  => $error,
                    'path'       => '',
                    'hash'       => md5($to . '|' . $subject . '|' . $date),
                ]);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log(
                'MailService could not record a failed send to ' . $to . ': ' . $e->getMessage(),
                'radiochatbox'
            );
        }
    }
}
