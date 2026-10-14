# Mail templates

The reusable texts the site sends: password resets, confirmations, notices and any other message the application builds from a template. Edit the wording here instead of asking a developer to change code.

## What you can do here

- **Browse** the templates. Language versions of the same message are grouped together, so you can see which languages each message is translated into.
- **View** a template read-only, with the list of placeholders it uses.
- **Edit** a template, or **create** a new one. A template needs a category and a title.
- **Delete** a template.
- **Send a test** to any email address you type, to see how the template actually looks in a mailbox.

## Placeholders

A placeholder is a word in curly braces, such as `{name}` or `{link}`. The site replaces it with the real value when it sends the message. The view page lists every placeholder the template contains, read from the template itself.

## Good to know

- Keep placeholders spelled exactly as they are. A typo such as `{nmae}` is not replaced and arrives in the mail as written.
- In a test send, each placeholder is filled with its own name in square brackets, so you can see where every value will land.
- Test sends go to the address you type, never to a user account.
- The body is stored as written, including HTML. That is intended: an email template is markup.
- A template is found by its category, language and type. Changing the category or type of an existing template can stop the site from finding it.
- If a test is refused by the mailer, check the email settings on the Settings page.

## Common questions

- **I changed a template and nothing changed.** Check that you edited the version in the recipient's language.
