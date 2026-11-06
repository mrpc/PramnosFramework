# Permissions
<!-- questions: How do I see who may do what? | How do I allow or deny an action to a user or role? -->

The individual permission grants of the site: who may do what, to which kind of thing. Each row says that a user or a role is allowed, or denied, an action on a resource.

## What you can do here
<!-- questions: How do I create a permission grant? | How do I filter permissions by subject or resource? -->

- **Browse and filter** the grants by subject, resource or action.
- **Create** a grant or **edit** one.
- **Delete** a grant.

## The fields
<!-- questions: What do the permission fields mean? | How do I grant a permission on every item of a kind? -->

| Field | Meaning |
|---|---|
| Subject type and ID | Who it applies to: a *user* or a *role*, and its id. |
| Object type | The kind of thing, for example `reports` or `users`. |
| Object ID | One specific item. Leave it empty for all items of that type. |
| Action | What may be done, for example `view` or `edit`. `*` means every action. |
| Grant type | *Allow* or *Deny*. |
| Priority | When an allow and a deny meet, the higher priority wins; a deny wins a tie. |
| Application | Limits the grant to one connected application. |
| Conditions | Optional extra rules, written as JSON, for example `{"location_id": [1, 2]}`. |
| Expires | Leave empty for a permanent grant. |

## Good to know
<!-- questions: How do I make an exception to an allow? | Why is a permission grant marked as not matching? -->

- Use a *Deny* to make an exception to a broader *Allow*, and give it a higher priority if it must win.
- When an application has declared its resources and scopes, a grant for it must use those names. The list marks grants that no longer match.
- Changes take effect straight away, and connected applications are told to refresh the affected permissions.
- Granting to a role is usually easier to manage than granting to many users one by one.
- You can also grant a permission from a user's own edit page.
