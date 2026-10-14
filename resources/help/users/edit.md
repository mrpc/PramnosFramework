# Edit user

The form for creating an account or changing an existing one. It has three separate parts, each with its own Save button: the account's details, its per-user settings, and the permissions granted directly to it.

## What you can do here

- **Account details**: username, email, first and last name, phone, mobile, language, time zone, user type and password.
- **Settings**: add, change or remove a per-user setting, such as a feature switch or a quota the application keeps about this account.
- **Permissions**: grant a permission directly to this person, or revoke one granted here.

## Good to know

- Leave the password empty to keep the current one. Type a new one only to change it.
- You cannot give anyone a user type higher than your own, nor edit an account that ranks above you.
- Each part saves on its own. Saving the details does not touch the settings or permissions, and the other way round.
- A setting's value can be plain text or JSON. Text that is valid JSON, such as a list, is stored as that structure.
- Removing a setting deletes it, so the application's default applies again. That is different from saving it empty.
- Only permissions granted directly to this account are listed here. The person may have more through roles, groups or their user type; those are managed on the Roles, Groups and Permissions screens.
- A permission has an object type (what kind of thing), an optional object id (one item; empty for all), an action, allow or deny, and a priority. The higher priority wins, and a deny wins a tie.

