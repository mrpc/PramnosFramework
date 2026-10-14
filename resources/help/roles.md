# Roles

A role is a named set of permissions, such as "editor" or "operator". Give a role to people instead of granting each permission one by one.

## What you can do here

- **Browse** the roles.
- **View** a role: what it is, which permissions it grants, and who holds it.
- **Create** a role or **edit** one: a name, a description, and whether it is system-wide or belongs to one organisation.
- **Deactivate** a role.
- **Members**: see who holds the role, **give** it to a user, or **take it away**.
- Choose which **administration screens** the role opens, when the site grants screen access by permission.

## Good to know

- A role does nothing until it has permissions. Add them on the Permissions screen with the subject type set to *role*; the role's View page then lists them.
- Deactivating keeps the role and its records but stops it counting: nobody gets its permissions while it is inactive.
- A role that belongs to an organisation can only be given to members of that organisation. If giving it fails, add the person to the organisation first.
- Changes take effect straight away, and connected applications are told that the holders' permissions changed.
- Roles can also be given to whole groups from the Groups screen.

## Common questions

- **Why can someone still do something after I removed their role?** They may hold the same permission directly, through another role, through a group, or through their user type.
- **Can I delete a role completely?** No, only deactivate it, so existing permission records still make sense.
