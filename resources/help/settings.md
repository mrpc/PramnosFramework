# Settings

The site-wide settings: the site's name and address, email delivery, sign-in security, registration, trusted devices and the cookie banner. Changes apply as soon as you save.

## What you can do here

- **General**: site name, site address, default language, time zone, and whether HTTPS is forced.
- **Email / SMTP**: the sender and reply-to addresses, and the mail server the site sends through.
- **Security**: login lockout rules after failed attempts, and what happens when someone signs in from a new device.
- **Registration**: whether anybody may register, and whether only certain email domains are allowed.
- **Trusted devices**: "Don't ask again on this device", and approving sign-ins from a trusted phone.
- **Cookie consent**: the banner, its categories and the policy page.
- **Advanced / Raw Settings**: every stored setting as a plain key and value, to add, edit or remove one directly.

## Good to know

- The SMTP password is never shown. Leave the field empty to keep the saved password; type a new one only to change it.
- Lockout durations must increase from one rule to the next.
- The strictest new-device rules (require a second factor or a passkey) can lock people out who have not set one up. Make sure your users have enrolled before turning them on.
- Which sign-in factors and features exist is fixed in the application's configuration; it is shown here but changed by a developer.
- Database connection settings cannot be viewed or changed from this page.
- Use the raw list with care: values are saved exactly as typed.

## Common questions

- **Test emails are not arriving.** Check the SMTP host, port, username and TLS setting, then look at the Email log for the error.
- **A user is locked out after failed sign-ins.** Wait for the lockout to end, or use **Clear lockout** on their page in the Users screen where it is offered.
