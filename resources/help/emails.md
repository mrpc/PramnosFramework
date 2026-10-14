# Email log

A record of every email the site has sent, tried to send, or is about to send. Use it to answer "did this person get our email?".

## What you can do here

- **Search and sort** the list. Each column has its own search box.
- **View** an email to see exactly what was sent, including the full message as the recipient saw it.
- **Resend** a failed email. It is queued again and the mail sender tries once more.
- See **tracking** results, where tracking was used: opens, proxy opens and clicks.

## Statuses

| Status | Meaning |
|---|---|
| Sent | Handed over to the mail server. |
| Failed | The mail server refused it or could not be reached. |
| Queued | Waiting for the mail sender to pick it up. |

## Good to know

- The log is read-only. Sent emails cannot be edited or deleted here, so the record stays intact.
- "Sent" means the mail server accepted it, not that it reached the inbox. A message can still land in spam or be rejected later by the recipient's provider.
- Opened from a user's page, the log shows only that address. Mail sent to an older address of the same person is not included.
- Tracking is off for most mail. An empty tracking cell means nobody measured it, not that nobody read it.
- Proxy opens are counted apart from opens. Some mail apps fetch images automatically, so a proxy open does not prove a person read the message. A click does.

## Common questions

- **An email stays Queued.** The mail sender may not be running. Check the Services and Queue pages.
- **Someone says they got nothing, but it shows Sent.** Ask them to check spam, and confirm the address is spelled correctly.
